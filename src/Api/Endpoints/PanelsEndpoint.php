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
 * PanelsEndpoint — GET /panels
 *
 * Vue agrégée des hosts IPTV (panels) : actifs, expirés, total, LTV.
 * Délègue à \IptvCore\Admin\Cockpit\PanelsDataSource::list() quand iptv-core est dispo.
 */
final class PanelsEndpoint
{
    public static function list(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;

        $class = '\\IptvCore\\Admin\\Cockpit\\PanelsDataSource';
        if (!class_exists($class) || !method_exists($class, 'list')) {
            return new WP_Error('iptv_connect_panels_unavailable', 'PanelsDataSource introuvable.', ['status' => 503]);
        }

        try {
            $items = call_user_func([$class, 'list']);
        } catch (\Throwable $e) {
            return new WP_Error('iptv_connect_panels_exception', $e->getMessage(), ['status' => 500]);
        }

        return new WP_REST_Response([
            'items' => is_array($items) ? array_values($items) : [],
            'total' => is_array($items) ? count($items) : 0,
        ], 200);
    }
}
