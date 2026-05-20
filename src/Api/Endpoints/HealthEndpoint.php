<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Api\Endpoints;

use WP_REST_Request;
use WP_REST_Response;

/**
 * HealthEndpoint — GET /health
 *
 * Publique, retourne ping + version + stack info.
 * Pas de data métier exposée (sans auth).
 */
final class HealthEndpoint
{
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok'                  => true,
            'plugin_version'      => defined('IPTV_CONNECT_VERSION') ? IPTV_CONNECT_VERSION : 'unknown',
            'wp_version'          => get_bloginfo('version'),
            'php_version'         => PHP_VERSION,
            'site_url'            => home_url('/'),
            'site_name'           => get_bloginfo('name'),
            'has_woocommerce'     => class_exists('WooCommerce'),
            'has_iptv_core'       => post_type_exists('iptv_dossier'),
            'time'                => current_time('mysql'),
            'timestamp'           => time(),
        ], 200);
    }
}
