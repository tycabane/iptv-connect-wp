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
 * DossiersEndpoint — GET /dossiers · GET /dossiers/{id}
 *
 * Compatible avec ou sans le plugin iptv-core :
 *   - Si CPT iptv_dossier existe → lit directement les dossiers
 *   - Sinon → fallback sur les WC orders complétés comme "dossiers virtuels"
 *   - Sinon (pas de WC) → retourne array vide avec info dans le payload
 */
final class DossiersEndpoint
{
    /**
     * GET /dossiers (liste paginée)
     */
    public static function list(WP_REST_Request $request): WP_REST_Response
    {
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = max(1, min(200, (int) $request->get_param('per_page')));
        $search   = sanitize_text_field((string) $request->get_param('search'));
        $status   = sanitize_text_field((string) $request->get_param('status'));

        if (post_type_exists('iptv_dossier')) {
            return self::listFromIptvCore($page, $per_page, $search, $status);
        }
        if (class_exists('WooCommerce')) {
            return self::listFromWcOrders($page, $per_page, $search, $status);
        }
        return new WP_REST_Response([
            'items' => [],
            'total' => 0,
            'page'  => $page,
            'note'  => 'Aucun système de dossiers détecté (ni iptv-core, ni WooCommerce).',
        ], 200);
    }

    /**
     * GET /dossiers/{id} (détail)
     */
    public static function get(WP_REST_Request $request)
    {
        $id  = (int) $request->get_param('id');
        $inc = (bool) $request->get_param('include_credentials');
        if ($id <= 0) {
            return new WP_Error('iptv_connect_bad_id', 'ID invalide', ['status' => 400]);
        }

        if (post_type_exists('iptv_dossier')) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'iptv_dossier') {
                return new WP_Error('iptv_connect_not_found', 'Dossier introuvable', ['status' => 404]);
            }
            return new WP_REST_Response(self::serializeIptvDossier($post, $inc), 200);
        }
        if (class_exists('WooCommerce')) {
            $order = wc_get_order($id);
            if (!$order) {
                return new WP_Error('iptv_connect_not_found', 'Commande introuvable', ['status' => 404]);
            }
            return new WP_REST_Response(self::serializeWcOrder($order), 200);
        }
        return new WP_Error('iptv_connect_no_source', 'Pas de source de dossiers configurée', ['status' => 503]);
    }

    /* ─────────────────────────────────────────────────────
       Source 1 : iptv-core (CPT iptv_dossier)
       ───────────────────────────────────────────────────── */

    private static function listFromIptvCore(int $page, int $per_page, string $search, string $status): WP_REST_Response
    {
        $args = [
            'post_type'      => 'iptv_dossier',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if ($status !== '') {
            $args['meta_query'] = [['key' => '_iptv_statut', 'value' => $status]];
        }
        if ($search !== '') {
            $args['s'] = $search;
        }

        $q = new \WP_Query($args);
        $items = [];
        foreach ($q->posts as $post) {
            $items[] = self::serializeIptvDossier($post, false);
        }

        return new WP_REST_Response([
            'source'   => 'iptv-core',
            'items'    => $items,
            'total'    => (int) $q->found_posts,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) $q->max_num_pages,
        ], 200);
    }

    /**
     * @param bool $include_creds Si true, déchiffre les credentials (sensible — admin-only).
     */
    private static function serializeIptvDossier(\WP_Post $post, bool $include_creds): array
    {
        $id = $post->ID;
        $data = [
            'id'             => $id,
            'created_at'     => $post->post_date_gmt,
            'updated_at'     => $post->post_modified_gmt,
            'email'          => (string) get_post_meta($id, '_iptv_client_email', true),
            'user_id'        => (int)    get_post_meta($id, '_iptv_client_user_id', true),
            'formule'        => (string) get_post_meta($id, '_iptv_formule', true),
            'duree_mois'     => (int)    get_post_meta($id, '_iptv_duree_mois', true),
            'ecrans'         => (int)    get_post_meta($id, '_iptv_ecrans', true),
            'prix_eur'       => (float)  get_post_meta($id, '_iptv_prix', true),
            'statut'         => (string) get_post_meta($id, '_iptv_statut', true),
            'exp_date'       => (string) get_post_meta($id, '_iptv_date_expiration', true),
            'host'           => (string) get_post_meta($id, '_iptv_creds_host_clear', true),
            'user_iptv'      => (string) get_post_meta($id, '_iptv_creds_user_clear', true),
            'wc_order_id'    => (int)    get_post_meta($id, '_iptv_wc_order_id', true),
            'newpanel_line'  => (int)    get_post_meta($id, '_iptv_newpanel_line_id', true),
            'newpanel_managed' => (int) get_post_meta($id, '_iptv_newpanel_managed', true) === 1,
        ];

        // Credentials sensibles (uniquement si demandés explicitement)
        if ($include_creds && class_exists('IptvCore\\Security\\DossierCreds') && class_exists('IptvCore\\Security\\CredsVault')) {
            try {
                $vault = new \IptvCore\Security\CredsVault();
                $creds = new \IptvCore\Security\DossierCreds($vault);
                $all   = $creds->getAll($id);
                if (is_array($all)) {
                    $data['credentials'] = [
                        'host' => (string) ($all['host'] ?? ''),
                        'port' => (string) ($all['port'] ?? ''),
                        'user' => (string) ($all['user'] ?? ''),
                        'pwd'  => (string) ($all['pwd']  ?? ''),
                        'url'  => (string) ($all['url']  ?? ''),
                    ];
                }
            } catch (\Throwable $e) {
                $data['credentials_error'] = $e->getMessage();
            }
        }
        return $data;
    }

    /* ─────────────────────────────────────────────────────
       Source 2 : WooCommerce (fallback)
       ───────────────────────────────────────────────────── */

    private static function listFromWcOrders(int $page, int $per_page, string $search, string $status): WP_REST_Response
    {
        $args = [
            'limit'    => $per_page,
            'paged'    => $page,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'status'   => $status !== '' ? [$status] : ['processing', 'completed', 'on-hold'],
        ];
        if ($search !== '') {
            $args['billing_email'] = $search;
        }
        $orders = wc_get_orders($args);
        $items = [];
        foreach ($orders as $o) {
            if (!$o instanceof \WC_Order) continue;
            $items[] = self::serializeWcOrder($o);
        }

        return new WP_REST_Response([
            'source'   => 'woocommerce',
            'items'    => $items,
            'total'    => count($items),
            'page'     => $page,
            'per_page' => $per_page,
        ], 200);
    }

    private static function serializeWcOrder(\WC_Order $order): array
    {
        $items_names = [];
        foreach ($order->get_items() as $item) $items_names[] = $item->get_name();

        return [
            'id'             => $order->get_id(),
            'created_at'     => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
            'email'          => (string) $order->get_billing_email(),
            'user_id'        => $order->get_customer_id(),
            'product'        => implode(' · ', $items_names),
            'prix_eur'       => (float) $order->get_total(),
            'currency'       => $order->get_currency(),
            'statut'         => $order->get_status(),
            'billing_country'=> $order->get_billing_country(),
            'wc_order_id'    => $order->get_id(),
        ];
    }
}
