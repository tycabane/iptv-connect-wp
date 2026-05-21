<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Api\Endpoints;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * NewPanelConfigEndpoint — endpoint pour pousser la config NewPanel depuis
 * le dashboard externe.
 *
 * Le dashboard centralise la gestion des credentials NewPanel + mapping durée
 * → package. Quand l'admin sauvegarde la config dans le dashboard UI, il POST
 * vers ce endpoint pour propager la config locale aux wp_options du site.
 *
 * Sécurité :
 *   - Auth Bearer (clé API iptv-connect — déjà vérifiée par RestController)
 *   - Validation stricte des champs (sanitize_text_field + intval)
 *   - Pas de remplacement de la constante IPTV_NEWPANEL_API_KEY si définie
 *     (la constante reste prioritaire pour les sites qui préfèrent la sécurité
 *     stricte via wp-config.php)
 *
 * Routes :
 *   - GET  /config/newpanel : retourne la config actuelle (api_key masquée)
 *   - POST /config/newpanel : update la config (api_key remplaçable)
 */
final class NewPanelConfigEndpoint
{
    public const OPT_API_KEY          = 'iptv_newpanel_api_key';
    public const OPT_MAPPING          = 'iptv_newpanel_mapping';
    public const OPT_DEFAULT_TEMPLATE = 'iptv_newpanel_default_template_id';
    public const OPT_DEFAULT_DOMAIN   = 'iptv_newpanel_default_domain_id';
    public const OPT_CREDIT_THRESHOLD = 'iptv_newpanel_credit_alert_threshold';
    public const OPT_LAST_PUSHED_AT   = 'iptv_newpanel_config_last_pushed_at';
    public const OPT_LAST_PUSHED_BY   = 'iptv_newpanel_config_last_pushed_by';

    /**
     * GET /config/newpanel — retourne la config actuelle (clé API masquée pour sécurité).
     */
    public static function get(WP_REST_Request $request): WP_REST_Response
    {
        $api_key_via_constant = defined('IPTV_NEWPANEL_API_KEY') && !empty(IPTV_NEWPANEL_API_KEY);
        $api_key_via_option   = !empty(get_option(self::OPT_API_KEY, ''));

        // Masquer la clé : afficher juste les 4 premiers et derniers chars
        $api_key_preview = '';
        if ($api_key_via_constant) {
            $k = (string) IPTV_NEWPANEL_API_KEY;
            $api_key_preview = substr($k, 0, 4) . '...' . substr($k, -4) . ' (wp-config.php)';
        } elseif ($api_key_via_option) {
            $k = (string) get_option(self::OPT_API_KEY, '');
            $api_key_preview = substr($k, 0, 4) . '...' . substr($k, -4) . ' (wp_option, dashboard)';
        }

        return new WP_REST_Response([
            'configured'              => $api_key_via_constant || $api_key_via_option,
            'source'                  => $api_key_via_constant ? 'constant' : ($api_key_via_option ? 'option' : null),
            'api_key_preview'         => $api_key_preview,
            'mapping'                 => (array) get_option(self::OPT_MAPPING, []),
            'default_template_id'    => (int) get_option(self::OPT_DEFAULT_TEMPLATE, 0),
            'default_domain_id'      => (int) get_option(self::OPT_DEFAULT_DOMAIN, 0),
            'credit_alert_threshold' => (int) get_option(self::OPT_CREDIT_THRESHOLD, 2),
            'last_pushed_at'         => (string) get_option(self::OPT_LAST_PUSHED_AT, ''),
            'last_pushed_by'         => (string) get_option(self::OPT_LAST_PUSHED_BY, ''),
        ], 200);
    }

    /**
     * POST /config/newpanel — update la config.
     *
     * Body JSON attendu :
     *   {
     *     "api_key": "...",                    // string, optionnel (ne change pas si absent)
     *     "mapping": {"1m":4, "3m":6, ...},    // object {string: int}
     *     "default_template_id": 2,            // int
     *     "default_domain_id": 3139,           // int
     *     "credit_alert_threshold": 2,         // int (default 2)
     *     "pushed_by": "admin@example.com"     // string (audit)
     *   }
     */
    public static function put(WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_Error('iptv_connect_bad_body', 'Body JSON invalide', ['status' => 400]);
        }

        // Snapshot AVANT modification (pour calculer les vraies diffs)
        $before = [
            'mapping'                 => (array) get_option(self::OPT_MAPPING, []),
            'default_template_id'    => (int) get_option(self::OPT_DEFAULT_TEMPLATE, 0),
            'default_domain_id'      => (int) get_option(self::OPT_DEFAULT_DOMAIN, 0),
            'credit_alert_threshold' => (int) get_option(self::OPT_CREDIT_THRESHOLD, 2),
        ];

        $changes = [];

        // API key (seulement si fournie ET pas de constante prioritaire)
        if (isset($body['api_key']) && is_string($body['api_key']) && $body['api_key'] !== '') {
            if (defined('IPTV_NEWPANEL_API_KEY') && !empty(IPTV_NEWPANEL_API_KEY)) {
                $changes[] = 'api_key_skipped_constant_defined';
            } else {
                update_option(self::OPT_API_KEY, sanitize_text_field((string) $body['api_key']));
                $changes[] = 'api_key';
            }
        }

        // Mapping — sauvegarde + diff réel
        $new_mapping = null;
        if (isset($body['mapping']) && is_array($body['mapping'])) {
            $clean = [];
            foreach (['1m', '3m', '6m', '12m', '24m'] as $key) {
                if (isset($body['mapping'][$key]) && (int) $body['mapping'][$key] > 0) {
                    $clean[$key] = (int) $body['mapping'][$key];
                }
            }
            update_option(self::OPT_MAPPING, $clean);
            $new_mapping = $clean;
            // Diff réel : compare clé par clé
            if (self::mappingDiffers($before['mapping'], $clean)) {
                $changes[] = 'mapping';
            }
        }

        // Defaults (avec diff réel)
        if (isset($body['default_template_id'])) {
            $val = max(0, (int) $body['default_template_id']);
            update_option(self::OPT_DEFAULT_TEMPLATE, $val);
            if ($val !== $before['default_template_id']) $changes[] = 'default_template_id';
        }
        if (isset($body['default_domain_id'])) {
            $val = max(0, (int) $body['default_domain_id']);
            update_option(self::OPT_DEFAULT_DOMAIN, $val);
            if ($val !== $before['default_domain_id']) $changes[] = 'default_domain_id';
        }
        if (isset($body['credit_alert_threshold'])) {
            $val = max(0, (int) $body['credit_alert_threshold']);
            update_option(self::OPT_CREDIT_THRESHOLD, $val);
            if ($val !== $before['credit_alert_threshold']) $changes[] = 'credit_alert_threshold';
        }

        // Audit (toujours mis à jour, même si rien n'a vraiment changé : trace de la tentative)
        update_option(self::OPT_LAST_PUSHED_AT, gmdate('c'));
        $pushed_by = isset($body['pushed_by']) && is_string($body['pushed_by'])
            ? sanitize_text_field($body['pushed_by'])
            : '';
        if ($pushed_by !== '') {
            update_option(self::OPT_LAST_PUSHED_BY, $pushed_by);
        }

        // Event hook (pour Telegram, audit log, etc.)
        do_action('iptv_connect/config.newpanel.updated', [
            'changes'   => $changes,
            'pushed_by' => $pushed_by,
        ]);

        // État APRÈS : renvoyé dans la réponse pour que le client puisse vérifier
        // exactement ce qui est en BD (anti-faux-positif).
        $after = [
            'mapping'                 => (array) get_option(self::OPT_MAPPING, []),
            'default_template_id'    => (int) get_option(self::OPT_DEFAULT_TEMPLATE, 0),
            'default_domain_id'      => (int) get_option(self::OPT_DEFAULT_DOMAIN, 0),
            'credit_alert_threshold' => (int) get_option(self::OPT_CREDIT_THRESHOLD, 2),
        ];

        $message = empty($changes)
            ? 'Aucune modification détectée — les valeurs sont identiques à celles déjà en base.'
            : sprintf('Config NewPanel mise à jour (%d champ%s : %s)', count($changes), count($changes) > 1 ? 's' : '', implode(', ', $changes));

        return new WP_REST_Response([
            'ok'         => true,
            'changes'    => $changes,
            'message'    => $message,
            'persisted'  => $after, // ← preuve : ce qui est vraiment en BD maintenant
        ], 200);
    }

    /**
     * Compare deux mappings clé par clé. Retourne true si différent.
     */
    private static function mappingDiffers(array $a, array $b): bool
    {
        $keys = ['1m', '3m', '6m', '12m', '24m'];
        foreach ($keys as $k) {
            $va = isset($a[$k]) ? (int) $a[$k] : 0;
            $vb = isset($b[$k]) ? (int) $b[$k] : 0;
            if ($va !== $vb) return true;
        }
        return false;
    }
}
