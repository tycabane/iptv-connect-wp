<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Api\Endpoints;

use WP_REST_Request;
use WP_REST_Response;

/**
 * CacheEndpoint — outils admin pour purger les caches PHP côté site.
 *
 * Utilité : après un `wp plugin install --force`, OPcache peut garder
 * l'ancien bytecode en mémoire jusqu'au prochain restart worker PHP-FPM.
 * Cet endpoint permet de forcer un reset à la demande (cockpit ou script).
 *
 * Sécurité : protégé par Bearer auth (clé API iptv-connect).
 *
 * Routes :
 *   - POST /cache/clear : opcache_reset() best-effort
 */
final class CacheEndpoint
{
    public static function clear(WP_REST_Request $request): WP_REST_Response
    {
        $cleared = [];
        $skipped = [];

        // OPcache : reset complet du bytecode cache
        if (function_exists('opcache_reset')) {
            $ok = @opcache_reset();
            if ($ok) {
                $cleared[] = 'opcache';
            } else {
                $skipped[] = 'opcache (reset returned false — opcache.restrict_api blocking?)';
            }
        } else {
            $skipped[] = 'opcache (function not available)';
        }

        // Object cache WordPress (Memcached/Redis si actifs)
        if (function_exists('wp_cache_flush')) {
            @wp_cache_flush();
            $cleared[] = 'wp_object_cache';
        }

        // Transients expirés
        if (function_exists('delete_expired_transients')) {
            @delete_expired_transients();
            $cleared[] = 'expired_transients';
        }

        return new WP_REST_Response([
            'ok'      => true,
            'cleared' => $cleared,
            'skipped' => $skipped,
            'message' => empty($cleared)
                ? 'Aucun cache nettoyé (rien d\'actif).'
                : sprintf('Cache(s) nettoyé(s) : %s', implode(', ', $cleared)),
        ], 200);
    }
}
