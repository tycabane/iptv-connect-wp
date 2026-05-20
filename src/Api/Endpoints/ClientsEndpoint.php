<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Api\Endpoints;

use WP_REST_Request;
use WP_REST_Response;

/**
 * ClientsEndpoint — GET /clients
 *
 * Agrège les utilisateurs WP avec leurs dossiers + commandes associées.
 * Fonctionne avec ou sans iptv-core / WooCommerce.
 */
final class ClientsEndpoint
{
    public static function list(WP_REST_Request $request): WP_REST_Response
    {
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = max(1, min(200, (int) $request->get_param('per_page')));
        $search   = sanitize_text_field((string) $request->get_param('search'));

        $args = [
            'number' => $per_page,
            'paged'  => $page,
            'fields' => 'all',
        ];
        if ($search !== '') {
            $args['search']         = '*' . esc_attr($search) . '*';
            $args['search_columns'] = ['user_email', 'user_login', 'display_name'];
        }

        $query = new \WP_User_Query($args);
        $items = [];

        foreach ($query->get_results() as $user) {
            if (!$user instanceof \WP_User) continue;
            $items[] = self::serializeUser($user);
        }

        return new WP_REST_Response([
            'items'    => $items,
            'total'    => (int) $query->get_total(),
            'page'     => $page,
            'per_page' => $per_page,
        ], 200);
    }

    private static function serializeUser(\WP_User $user): array
    {
        $uid = $user->ID;

        // Dossiers liés (si iptv-core dispo)
        $dossiers_count = 0;
        $dossier_actif  = false;
        if (post_type_exists('iptv_dossier')) {
            $q = new \WP_Query([
                'post_type'      => 'iptv_dossier',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [
                    ['key' => '_iptv_client_user_id', 'value' => $uid],
                ],
            ]);
            $dossiers_count = (int) $q->found_posts;
            foreach ($q->posts as $pid) {
                if ((string) get_post_meta($pid, '_iptv_statut', true) === 'actif') {
                    $dossier_actif = true;
                    break;
                }
            }
        }

        // Commandes WC liées (si WC dispo)
        $orders_count = 0;
        $total_spent  = 0.0;
        if (class_exists('WooCommerce') && function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'customer_id' => $uid,
                'limit'       => -1,
                'status'      => ['processing', 'completed'],
            ]);
            $orders_count = count($orders);
            foreach ($orders as $o) {
                if ($o instanceof \WC_Order) {
                    $total_spent += (float) $o->get_total();
                }
            }
        }

        return [
            'id'             => $uid,
            'email'          => $user->user_email,
            'display_name'   => $user->display_name,
            'registered_at'  => $user->user_registered,
            'roles'          => $user->roles,
            'dossiers_count' => $dossiers_count,
            'has_active'     => $dossier_actif,
            'orders_count'   => $orders_count,
            'total_spent'    => round($total_spent, 2),
        ];
    }
}
