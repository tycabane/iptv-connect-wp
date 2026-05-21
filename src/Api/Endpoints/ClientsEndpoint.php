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
    /**
     * GET /clients/{id}/journey
     *
     * Vue 360° d'un client :
     *   - infos user (email, display_name, registered_at)
     *   - tous les dossiers (passés + actifs)
     *   - timeline d'events (audit_log filtré sur ses dossiers)
     *   - LTV totale, days since last activity, tags auto
     */
    public static function journey(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return new \WP_Error('iptv_connect_bad_id', 'ID invalide', ['status' => 400]);
        }
        $user = get_user_by('id', $id);
        if (!$user) {
            return new \WP_Error('iptv_connect_not_found', 'Client introuvable', ['status' => 404]);
        }

        // 1. Dossiers du client (via meta _iptv_client_user_id)
        $dossiers = [];
        if (post_type_exists('iptv_dossier')) {
            $q = new \WP_Query([
                'post_type'      => 'iptv_dossier',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => [
                    [
                        'relation' => 'OR',
                        ['key' => '_iptv_client_user_id', 'value' => $id],
                        ['key' => '_iptv_client_email',   'value' => $user->user_email],
                    ],
                ],
            ]);
            foreach ($q->posts as $post) {
                $dossiers[] = [
                    'id'         => $post->ID,
                    'created_at' => $post->post_date_gmt,
                    'updated_at' => $post->post_modified_gmt,
                    'email'      => (string) get_post_meta($post->ID, '_iptv_client_email', true),
                    'formule'    => (string) get_post_meta($post->ID, '_iptv_formule', true),
                    'duree_mois' => (int)    get_post_meta($post->ID, '_iptv_duree_mois', true),
                    'ecrans'     => (int)    get_post_meta($post->ID, '_iptv_ecrans', true),
                    'prix_eur'   => (float)  get_post_meta($post->ID, '_iptv_prix', true),
                    'statut'     => (string) get_post_meta($post->ID, '_iptv_statut', true),
                    'exp_date'   => (string) get_post_meta($post->ID, '_iptv_date_expiration', true),
                    'host'       => (string) get_post_meta($post->ID, '_iptv_creds_host_clear', true),
                    'wc_order_id'=> (int)    get_post_meta($post->ID, '_iptv_wc_order_id', true),
                ];
            }
        }

        // 2. Commandes WC (si dispo)
        $orders = [];
        if (class_exists('WooCommerce') && function_exists('wc_get_orders')) {
            $wc_orders = wc_get_orders([
                'customer'      => $id,
                'limit'         => -1,
                'orderby'       => 'date',
                'order'         => 'DESC',
            ]);
            foreach ($wc_orders as $o) {
                if (!$o instanceof \WC_Order) continue;
                $orders[] = [
                    'id'         => $o->get_id(),
                    'date'       => $o->get_date_created() ? $o->get_date_created()->date('c') : null,
                    'total'      => (float) $o->get_total(),
                    'status'     => $o->get_status(),
                    'currency'   => $o->get_currency(),
                ];
            }
        }

        // 3. Audit events sur les dossiers de ce client
        $events = [];
        $dossierIds = array_column($dossiers, 'id');
        if (!empty($dossierIds) && class_exists('\\IptvCore\\Security\\AuditLogger')) {
            global $wpdb;
            $table = $wpdb->prefix . 'iptv_audit_log';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) {
                $idsPlaceholders = implode(',', array_fill(0, count($dossierIds), '%d'));
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, user_id, action, target_type, target_id, created_at
                     FROM {$table}
                     WHERE target_type = 'dossier' AND target_id IN ({$idsPlaceholders})
                     ORDER BY created_at DESC
                     LIMIT 100",
                    ...$dossierIds
                ), ARRAY_A);
                $events = is_array($rows) ? $rows : [];
            }
        }

        // 4. Calcul LTV + tags
        $ltv = 0.0;
        $activeCount = 0;
        $lastActivity = null;
        foreach ($dossiers as $d) {
            $ltv += $d['prix_eur'];
            if ($d['statut'] === 'actif') $activeCount++;
            $when = $d['updated_at'] ?: $d['created_at'];
            if ($when && (!$lastActivity || $when > $lastActivity)) $lastActivity = $when;
        }
        foreach ($orders as $o) {
            if (in_array($o['status'], ['completed', 'processing'], true)) {
                // déjà compté via dossiers si lié, sinon ajoute (cas commandes WC sans dossier iptv-core)
                $linkedToDossier = false;
                foreach ($dossiers as $d) {
                    if ($d['wc_order_id'] === $o['id']) { $linkedToDossier = true; break; }
                }
                if (!$linkedToDossier) $ltv += $o['total'];
            }
        }

        $tags = [];
        if ($activeCount === 0 && count($dossiers) > 0) $tags[] = 'churn';
        if ($ltv >= 200) $tags[] = 'vip';
        if ($activeCount > 0) $tags[] = 'active';
        if ($lastActivity) {
            $daysSince = (int) floor((time() - strtotime($lastActivity)) / 86400);
            if ($daysSince >= 365) $tags[] = 'expired_1y+';
            elseif ($daysSince >= 90) $tags[] = 'expired_90d+';
        }

        return new \WP_REST_Response([
            'client' => [
                'id'             => $user->ID,
                'email'          => $user->user_email,
                'display_name'   => $user->display_name,
                'registered_at'  => $user->user_registered,
                'roles'          => $user->roles,
            ],
            'dossiers'  => $dossiers,
            'orders'    => $orders,
            'events'    => $events,
            'stats'     => [
                'ltv_eur'         => round($ltv, 2),
                'dossiers_total'  => count($dossiers),
                'dossiers_active' => $activeCount,
                'orders_total'    => count($orders),
                'last_activity'   => $lastActivity,
                'days_since_last' => $lastActivity ? (int) floor((time() - strtotime($lastActivity)) / 86400) : null,
                'tags'            => $tags,
            ],
        ], 200);
    }

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
