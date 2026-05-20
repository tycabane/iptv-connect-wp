<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Api\Endpoints;

use IptvConnect\Support\IptvCoreBridge;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * CronEndpoint
 *
 * GET /cron/renewal   · lit la config du cron renouvellement
 * PUT /cron/renewal   · sauvegarde la config (et reprogramme si auto = true)
 *
 * Délègue à \IptvCore\Cron\RenewalReminder (statique) si présent,
 * sinon manipule directement l'option `iptv_renewal_reminder_settings`.
 */
final class CronEndpoint
{
    private const OPT_KEY = 'iptv_renewal_reminder_settings';

    public static function getRenewal(WP_REST_Request $request)
    {
        $class = '\\IptvCore\\Cron\\RenewalReminder';
        $config = [
            'auto'           => false,
            'hour'           => 9,
            'triggers'       => [],
            'test_recipient' => '',
        ];

        if (class_exists($class)) {
            if (method_exists($class, 'isAutoEnabled')) $config['auto']    = (bool) call_user_func([$class, 'isAutoEnabled']);
            if (method_exists($class, 'getCronHour'))   $config['hour']    = (int)  call_user_func([$class, 'getCronHour']);
            if (method_exists($class, 'getEnabledTriggers')) {
                $val = call_user_func([$class, 'getEnabledTriggers']);
                $config['triggers'] = is_array($val) ? array_values($val) : [];
            }
        }

        $stored = get_option(self::OPT_KEY, []);
        if (is_array($stored)) {
            $config = array_merge($config, array_intersect_key($stored, $config));
        }

        // Info utile : prochain run programmé
        $config['next_scheduled'] = (int) (wp_next_scheduled('iptv_renewal_reminder_cron') ?: 0);

        return new WP_REST_Response($config, 200);
    }

    /**
     * Body : { auto?: bool, hour?: int, triggers?: string[], test_recipient?: string }
     */
    public static function saveRenewal(WP_REST_Request $request)
    {
        $body = (array) $request->get_json_params();

        $clean = [];
        if (array_key_exists('auto',           $body)) $clean['auto']           = (bool) $body['auto'];
        if (array_key_exists('hour',           $body)) $clean['hour']           = max(0, min(23, (int) $body['hour']));
        if (array_key_exists('test_recipient', $body)) $clean['test_recipient'] = sanitize_email((string) $body['test_recipient']);
        if (array_key_exists('triggers',       $body) && is_array($body['triggers'])) {
            $clean['triggers'] = array_values(array_unique(array_map('sanitize_key', $body['triggers'])));
        }

        if (empty($clean)) {
            return new WP_Error('iptv_connect_empty_body', 'Aucun champ valide fourni.', ['status' => 400]);
        }

        $class = '\\IptvCore\\Cron\\RenewalReminder';
        if (class_exists($class) && method_exists($class, 'saveSettings')) {
            try {
                call_user_func([$class, 'saveSettings'], $clean);
                // schedule / unschedule selon auto
                if (isset($clean['auto'])) {
                    if ($clean['auto'] && method_exists($class, 'schedule'))      call_user_func([$class, 'schedule']);
                    if (!$clean['auto'] && method_exists($class, 'unschedule'))   call_user_func([$class, 'unschedule']);
                }
                IptvCoreBridge::audit('EDIT_CRON_RENEWAL', 'option', 0, ['fields' => array_keys($clean)]);
                return self::getRenewal($request);
            } catch (\Throwable $e) {
                return new WP_Error('iptv_connect_cron_exception', $e->getMessage(), ['status' => 500]);
            }
        }

        // Fallback direct sur l'option
        $current = get_option(self::OPT_KEY, []);
        if (!is_array($current)) $current = [];
        $merged = array_merge($current, $clean);
        update_option(self::OPT_KEY, $merged);

        IptvCoreBridge::audit('EDIT_CRON_RENEWAL', 'option', 0, ['fields' => array_keys($clean)]);
        return self::getRenewal($request);
    }
}
