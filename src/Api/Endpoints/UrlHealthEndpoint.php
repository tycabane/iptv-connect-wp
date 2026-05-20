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
 * UrlHealthEndpoint — GET /url-health
 *
 * Retourne l'état de santé des hosts IPTV utilisés par les dossiers actifs.
 * Délègue à \IptvCore\UrlMonitor\HealthTable + Pinger (iptv-core v0.3+).
 *
 * Réponse :
 *   {
 *     "items": [
 *       {
 *         "host": "vpn.revoline33.xyz",
 *         "is_down": false,
 *         "stats_24h": { "total": 24, "ok": 23, "fail": 1, "uptime_pct": 95.83, "avg_latency_ms": 142 },
 *         "recent_pings": [{...}, {...}, ...]
 *       },
 *       ...
 *     ],
 *     "total": 3,
 *     "any_down": false,
 *     "config": { "auto_migrate": false, "fallback_host": "", "consecutive_fails": 3 }
 *   }
 */
final class UrlHealthEndpoint
{
    public static function list(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;

        $pingerClass  = '\\IptvCore\\UrlMonitor\\Pinger';
        $tableClass   = '\\IptvCore\\UrlMonitor\\HealthTable';
        $serviceClass = '\\IptvCore\\UrlMonitor\\Service';

        if (!class_exists($pingerClass) || !class_exists($tableClass)) {
            return new WP_Error(
                'iptv_connect_url_monitor_unavailable',
                'Module URL Monitor non disponible (requiert iptv-core v0.3+).',
                ['status' => 503]
            );
        }

        $hosts = call_user_func([$pingerClass, 'listActiveHosts']);
        $items = [];
        $anyDown = false;

        foreach ($hosts as $host) {
            $stats = call_user_func([$tableClass, 'stats'], $host, 24);
            $recent = call_user_func([$tableClass, 'recent'], $host, 10);
            $isDown = call_user_func([$tableClass, 'isDown'], $host, 3);
            if ($isDown) $anyDown = true;

            $items[] = [
                'host'         => $host,
                'is_down'      => $isDown,
                'stats_24h'    => $stats,
                'recent_pings' => $recent,
            ];
        }

        $config = [
            'auto_migrate'      => (bool) get_option($serviceClass::OPT_AUTO_MIGRATE, false),
            'fallback_host'     => (string) get_option($serviceClass::OPT_FALLBACK_HOST, ''),
            'consecutive_fails' => max(2, (int) get_option($serviceClass::OPT_CONSECUTIVE_FAILS, 3)),
        ];

        return new WP_REST_Response([
            'items'    => $items,
            'total'    => count($items),
            'any_down' => $anyDown,
            'config'   => $config,
        ], 200);
    }

    /**
     * POST /url-health/check — déclenche un check manuel (utile pour tester depuis le dashboard).
     */
    public static function triggerCheck(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;

        $serviceClass = '\\IptvCore\\UrlMonitor\\Service';
        if (!class_exists($serviceClass) || !method_exists($serviceClass, 'checkAll')) {
            return new WP_Error('iptv_connect_url_monitor_unavailable', 'Service indisponible.', ['status' => 503]);
        }

        try {
            call_user_func([$serviceClass, 'checkAll']);
        } catch (\Throwable $e) {
            return new WP_Error('iptv_connect_check_failed', $e->getMessage(), ['status' => 500]);
        }

        return new WP_REST_Response(['ok' => true, 'message' => 'Check déclenché'], 200);
    }
}
