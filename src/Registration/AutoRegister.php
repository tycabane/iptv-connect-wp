<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Registration;

use IptvConnect\Bootstrap;

/**
 * AutoRegister — enregistre automatiquement le site dans le dashboard externe
 * lors de l'activation du plugin.
 *
 * Configuration requise (wp-config.php OU options WP) :
 *   - IPTV_DASHBOARD_URL          ex: "https://iptv-admin-pied.vercel.app"
 *   - IPTV_REGISTRATION_SECRET    secret partagé avec le dashboard (HMAC SHA-256)
 *
 * Mécanisme :
 *   1. Hook activation : appelle register() une fois à l'activation du plugin
 *   2. Construit le payload (URL + nom + API key + versions)
 *   3. Signe en HMAC avec REGISTRATION_SECRET sur "<timestamp>.<body>"
 *   4. POST vers <dashboard_url>/api/sites/register
 *   5. Stocke le résultat dans wp_option pour debug + display admin notice
 *
 * Best-effort : si le dashboard est inaccessible OU la config absente,
 * l'activation se passe normalement et l'admin pourra re-register manuellement
 * depuis la SettingsPage.
 *
 * Re-registration manuelle : bouton "📡 Synchroniser avec le dashboard"
 * dans Réglages → 🔌 Dashboard Connect.
 */
final class AutoRegister
{
    public const URL_CONSTANT     = 'IPTV_DASHBOARD_URL';
    public const SECRET_CONSTANT  = 'IPTV_REGISTRATION_SECRET';
    public const OPT_URL          = 'iptv_connect_dashboard_url';
    public const OPT_SECRET       = 'iptv_connect_registration_secret';
    public const OPT_LAST_RESULT  = 'iptv_connect_last_registration';

    /**
     * Effectue l'enregistrement. Retourne array{ok:bool, message:string, site_id?:int}.
     */
    public static function register(): array
    {
        $url    = self::dashboardUrl();
        $secret = self::registrationSecret();

        if ($url === '' || $secret === '') {
            $result = [
                'ok'      => false,
                'message' => 'Auto-registration skipée : IPTV_DASHBOARD_URL ou IPTV_REGISTRATION_SECRET non configurés.',
                'at'      => current_time('mysql'),
            ];
            update_option(self::OPT_LAST_RESULT, $result, false);
            return $result;
        }

        $endpoint = rtrim($url, '/') . '/api/sites/register';
        $apiKey   = Bootstrap::getApiKey();
        if ($apiKey === '') {
            // Génère la clé si elle n'existe pas encore (cas activation initiale)
            $apiKey = Bootstrap::rotateApiKey();
        }

        $payload = [
            'site_url'       => home_url('/'),
            'site_name'      => (string) get_bloginfo('name'),
            'api_key'        => $apiKey,
            'plugin_version' => defined('IPTV_CONNECT_VERSION') ? IPTV_CONNECT_VERSION : 'unknown',
            'wp_version'     => (string) get_bloginfo('version'),
        ];
        $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $result = ['ok' => false, 'message' => 'wp_json_encode a échoué', 'at' => current_time('mysql')];
            update_option(self::OPT_LAST_RESULT, $result, false);
            return $result;
        }

        $ts        = time();
        $signature = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);

        $res = wp_remote_post($endpoint, [
            'timeout'  => 10,
            'headers'  => [
                'Content-Type'      => 'application/json',
                'X-Iptv-Timestamp'  => (string) $ts,
                'X-Iptv-Signature'  => $signature,
                'X-Iptv-Plugin'     => 'iptv-connect',
                'X-Iptv-Plugin-Version' => $payload['plugin_version'],
                'User-Agent'        => 'iptv-connect-registration/1.0',
            ],
            'body'     => $body,
        ]);

        if (is_wp_error($res)) {
            $result = [
                'ok'      => false,
                'message' => 'Erreur réseau : ' . $res->get_error_message(),
                'at'      => current_time('mysql'),
            ];
            update_option(self::OPT_LAST_RESULT, $result, false);
            return $result;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $resp = json_decode((string) wp_remote_retrieve_body($res), true);

        if ($code >= 200 && $code < 300 && is_array($resp) && !empty($resp['ok'])) {
            $result = [
                'ok'      => true,
                'action'  => (string) ($resp['action'] ?? ''),
                'site_id' => (int) ($resp['site_id'] ?? 0),
                'message' => (string) ($resp['message'] ?? 'Site enregistré'),
                'at'      => current_time('mysql'),
            ];
            update_option(self::OPT_LAST_RESULT, $result, false);
            // Garde aussi l'URL dans wp_option pour affichage admin (et si la constante n'est pas définie)
            update_option(self::OPT_URL, rtrim($url, '/'), false);
            return $result;
        }

        $result = [
            'ok'      => false,
            'message' => sprintf('HTTP %d : %s', $code, is_array($resp) ? ($resp['error'] ?? wp_remote_retrieve_body($res)) : wp_remote_retrieve_body($res)),
            'at'      => current_time('mysql'),
        ];
        update_option(self::OPT_LAST_RESULT, $result, false);
        return $result;
    }

    /**
     * Récupère l'URL du dashboard (constante prioritaire sur wp_option).
     */
    public static function dashboardUrl(): string
    {
        if (defined(self::URL_CONSTANT) && constant(self::URL_CONSTANT)) {
            return (string) constant(self::URL_CONSTANT);
        }
        return (string) get_option(self::OPT_URL, '');
    }

    /**
     * Récupère le secret HMAC (constante prioritaire sur wp_option).
     */
    public static function registrationSecret(): string
    {
        if (defined(self::SECRET_CONSTANT) && constant(self::SECRET_CONSTANT)) {
            return (string) constant(self::SECRET_CONSTANT);
        }
        return (string) get_option(self::OPT_SECRET, '');
    }

    /**
     * Dernier résultat d'enregistrement (pour affichage admin).
     * @return array<string,mixed>|null
     */
    public static function getLastResult(): ?array
    {
        $r = get_option(self::OPT_LAST_RESULT);
        return is_array($r) ? $r : null;
    }
}
