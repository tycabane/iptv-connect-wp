<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Webhooks;

/**
 * WebhookDispatcher — écoute les hooks `iptv_connect/*` (do_action) du plugin
 * et POST vers une URL externe (le dashboard) avec signature HMAC SHA-256.
 *
 * Configuration via wp_options :
 *   - iptv_connect_webhook_url    : URL absolue de réception (ex: https://iptv-admin.example.com/api/wh/wp)
 *   - iptv_connect_webhook_secret : secret partagé pour signature HMAC
 *
 * Si une de ces 2 options est vide, le dispatcher est désactivé (no-op).
 *
 * Format du payload POST :
 *   Headers:
 *     Content-Type: application/json
 *     X-Iptv-Event: <event_name>
 *     X-Iptv-Site: <site_url>
 *     X-Iptv-Timestamp: <unix_ts>
 *     X-Iptv-Signature: sha256=<hmac(timestamp + "." + body, secret)>
 *     X-Iptv-Plugin-Version: <plugin_version>
 *   Body JSON :
 *     {
 *       "event": "dossier.created",
 *       "site_url": "https://...",
 *       "timestamp": 1779200000,
 *       "data": { ... payload spécifique à l'event ... }
 *     }
 *
 * Retry : 3 tentatives avec backoff (0, 2s, 5s) si HTTP code != 2xx.
 * Best-effort : un échec n'interrompt pas l'action WP en cours.
 */
final class WebhookDispatcher
{
    public const OPT_URL    = 'iptv_connect_webhook_url';
    public const OPT_SECRET = 'iptv_connect_webhook_secret';

    /** Mapping event → fonction de mapping des args do_action vers payload data. */
    private const EVENTS = [
        'iptv_connect/dossier.created'         => 'dossier.created',
        'iptv_connect/dossier.updated'         => 'dossier.updated',
        'iptv_connect/dossier.deleted'         => 'dossier.deleted',
        'iptv_connect/dossier.renewed'         => 'dossier.renewed',
        'iptv_connect/dossier.provisioned'     => 'dossier.provisioned',
        'iptv_connect/dossier.host_migrated'   => 'dossier.host_migrated',
        'iptv_connect/credentials.rotated'     => 'credentials.rotated',
        // Events iptv-core (URL monitor) — forwardés vers le dashboard pour notif
        'iptv_core/url.down'                   => 'url.down',
        'iptv_core/url.recovered'              => 'url.recovered',
        'iptv_core/host.auto_migrated'         => 'host.auto_migrated',
    ];

    public static function register(): void
    {
        foreach (self::EVENTS as $hook => $event) {
            add_action($hook, function (...$args) use ($event) {
                self::dispatch($event, $args);
            }, 10, 99);
        }
    }

    /**
     * Construit le payload + POST. Best-effort (n'interrompt jamais l'action WP).
     *
     * @param array<int,mixed> $args
     */
    public static function dispatch(string $event, array $args): void
    {
        $url    = (string) get_option(self::OPT_URL, '');
        $secret = (string) get_option(self::OPT_SECRET, '');
        if ($url === '' || $secret === '') return;

        $payload = [
            'event'     => $event,
            'site_url'  => home_url('/'),
            'timestamp' => time(),
            'data'      => self::mapArgs($event, $args),
        ];

        $body      = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = self::sign((string) $body, $secret, $payload['timestamp']);

        // Try synchronous (rapide), si échoue tente async (avec wp_schedule_single_event)
        $ok = self::doPost($url, (string) $body, $signature, (int) $payload['timestamp']);
        if (!$ok) {
            // Schedule un retry async dans 30s (wp-cron)
            wp_schedule_single_event(time() + 30, 'iptv_connect_webhook_retry', [$url, $body, $signature, $payload['timestamp'], 1]);
        }
    }

    /**
     * Hook cron de retry.
     */
    public static function retryHandler(string $url, string $body, string $signature, int $ts, int $attempt): void
    {
        $ok = self::doPost($url, $body, $signature, $ts);
        if (!$ok && $attempt < 3) {
            wp_schedule_single_event(time() + ($attempt * 60), 'iptv_connect_webhook_retry', [$url, $body, $signature, $ts, $attempt + 1]);
        }
    }

    private static function doPost(string $url, string $body, string $signature, int $ts): bool
    {
        $res = wp_remote_post($url, [
            'timeout'  => 6,
            'blocking' => true,
            'headers'  => [
                'Content-Type'           => 'application/json',
                'X-Iptv-Event'           => self::extractEventHeader($body),
                'X-Iptv-Site'            => (string) home_url('/'),
                'X-Iptv-Timestamp'       => (string) $ts,
                'X-Iptv-Signature'       => $signature,
                'X-Iptv-Plugin-Version'  => defined('IPTV_CONNECT_VERSION') ? IPTV_CONNECT_VERSION : 'unknown',
            ],
            'body'     => $body,
        ]);
        if (is_wp_error($res)) return false;
        $code = (int) wp_remote_retrieve_response_code($res);
        return $code >= 200 && $code < 300;
    }

    private static function extractEventHeader(string $body): string
    {
        // Extraction rapide sans json_decode complet
        if (preg_match('/"event":"([^"]+)"/', $body, $m)) return $m[1];
        return 'unknown';
    }

    /** Signature HMAC : sha256(timestamp + "." + body) */
    private static function sign(string $body, string $secret, int $timestamp): string
    {
        $hmac = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        return 'sha256=' . $hmac;
    }

    /**
     * Mappe les args génériques de chaque hook vers un payload data structuré.
     * Chaque hook a sa propre signature (cf. DossiersEndpoint::create/update/etc.).
     *
     * @param array<int,mixed> $args
     * @return array<string,mixed>
     */
    private static function mapArgs(string $event, array $args): array
    {
        switch ($event) {
            case 'dossier.created':
                // do_action('iptv_connect/dossier.created', (int) $post_id, array $body)
                return [
                    'dossier_id' => (int) ($args[0] ?? 0),
                    'fields'     => is_array($args[1] ?? null) ? $args[1] : [],
                ];

            case 'dossier.updated':
                // do_action('iptv_connect/dossier.updated', $id, $body)
                return [
                    'dossier_id' => (int) ($args[0] ?? 0),
                    'fields'     => is_array($args[1] ?? null) ? array_keys($args[1]) : [],
                ];

            case 'dossier.deleted':
                return ['dossier_id' => (int) ($args[0] ?? 0)];

            case 'dossier.renewed':
                // do_action('iptv_connect/dossier.renewed', $id, $months, $result)
                return [
                    'dossier_id' => (int) ($args[0] ?? 0),
                    'months'     => (int) ($args[1] ?? 0),
                    'result'     => is_array($args[2] ?? null) ? $args[2] : null,
                ];

            case 'dossier.provisioned':
                // do_action('iptv_connect/dossier.provisioned', $id, $result)
                return [
                    'dossier_id' => (int) ($args[0] ?? 0),
                    'result'     => is_array($args[1] ?? null) ? $args[1] : null,
                ];

            case 'dossier.host_migrated':
                // do_action('iptv_connect/dossier.host_migrated', $id, $old_host, $new_host)
                return [
                    'dossier_id' => (int) ($args[0] ?? 0),
                    'old_host'   => (string) ($args[1] ?? ''),
                    'new_host'   => (string) ($args[2] ?? ''),
                ];

            case 'credentials.rotated':
                // do_action('iptv_connect/credentials.rotated', $id, $field)
                return [
                    'dossier_id' => (int) ($args[0] ?? 0),
                    'field'      => (string) ($args[1] ?? ''),
                ];

            case 'url.down':
                // do_action('iptv_core/url.down', $host, $details)
                return [
                    'host'    => (string) ($args[0] ?? ''),
                    'details' => is_array($args[1] ?? null) ? $args[1] : null,
                ];

            case 'url.recovered':
                // do_action('iptv_core/url.recovered', $host)
                return ['host' => (string) ($args[0] ?? '')];

            case 'host.auto_migrated':
                // do_action('iptv_core/host.auto_migrated', $oldHost, $newHost, $dossierIds)
                return [
                    'old_host'    => (string) ($args[0] ?? ''),
                    'new_host'    => (string) ($args[1] ?? ''),
                    'dossier_ids' => is_array($args[2] ?? null) ? array_map('intval', $args[2]) : [],
                    'count'       => is_array($args[2] ?? null) ? count($args[2]) : 0,
                ];

            default:
                return [];
        }
    }
}
