<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Api\Endpoints;

use WP_REST_Request;
use WP_REST_Response;

/**
 * KpisEndpoint — GET /kpis
 *
 * Métriques business : MRR, dossiers actifs, expirent <30j, new <30j, revenus 30j.
 * Fonctionne avec iptv-core ET/OU WooCommerce. Tout fallback gracieusement.
 */
final class KpisEndpoint
{
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $now      = time();
        $j30_ago  = $now - (30 * DAY_IN_SECONDS);
        $j30_fwd  = $now + (30 * DAY_IN_SECONDS);

        $kpis = [
            'generated_at'        => current_time('mysql'),
            'has_iptv_core'       => post_type_exists('iptv_dossier'),
            'has_woocommerce'     => class_exists('WooCommerce'),
        ];

        // ───────────── Dossiers (iptv-core) ─────────────
        if (post_type_exists('iptv_dossier')) {
            $kpis += self::kpisFromIptvCore($now, $j30_ago, $j30_fwd);
        }

        // ───────────── Revenus (WooCommerce) ─────────────
        if (class_exists('WooCommerce') && function_exists('wc_get_orders')) {
            $kpis += self::kpisFromWooCommerce($j30_ago);
        }

        // ───────────── Utilisateurs ─────────────
        $kpis['users_total']    = (int) count_users()['total_users'];

        return new WP_REST_Response($kpis, 200);
    }

    private static function kpisFromIptvCore(int $now, int $j30_ago, int $j30_fwd): array
    {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'iptv_dossier' AND post_status = 'publish'"
        );

        $actifs = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type='iptv_dossier' AND p.post_status='publish'
             AND m.meta_key='_iptv_statut' AND m.meta_value='actif'"
        );

        // Expirent dans les 30 jours
        $exp_soon = 0;
        $exp_30j  = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type='iptv_dossier' AND p.post_status='publish'
             AND m.meta_key='_iptv_date_expiration'
             AND m.meta_value BETWEEN %s AND %s",
            date('Y-m-d', $now),
            date('Y-m-d', $j30_fwd)
        ));
        $exp_soon = $exp_30j;

        // Nouveaux dossiers (30 derniers jours)
        $new_30j = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type='iptv_dossier' AND post_status='publish'
             AND post_date_gmt >= %s",
            date('Y-m-d H:i:s', $j30_ago)
        ));

        // MRR : somme des prix dossiers actifs ÷ durée mois (approximation)
        $rows = $wpdb->get_results(
            "SELECT p.ID,
              MAX(CASE WHEN m.meta_key='_iptv_prix' THEN m.meta_value END) AS prix,
              MAX(CASE WHEN m.meta_key='_iptv_duree_mois' THEN m.meta_value END) AS duree
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID
             WHERE p.post_type='iptv_dossier' AND p.post_status='publish'
             AND ms.meta_key='_iptv_statut' AND ms.meta_value='actif'
             GROUP BY p.ID"
        );
        $mrr = 0.0;
        foreach ((array) $rows as $r) {
            $prix  = (float) $r->prix;
            $duree = max(1, (int) $r->duree);
            if ($prix > 0) $mrr += $prix / $duree;
        }

        return [
            'dossiers_total'       => $total,
            'dossiers_actifs'      => $actifs,
            'dossiers_expirent_30j'=> $exp_soon,
            'dossiers_new_30j'     => $new_30j,
            'mrr_eur'              => round($mrr, 2),
        ];
    }

    private static function kpisFromWooCommerce(int $j30_ago): array
    {
        $orders_30j = wc_get_orders([
            'limit'        => -1,
            'status'       => ['processing', 'completed'],
            'date_created' => '>=' . date('Y-m-d', $j30_ago),
        ]);

        $revenue_30j = 0.0;
        $count_30j   = 0;
        foreach ($orders_30j as $o) {
            if ($o instanceof \WC_Order) {
                $revenue_30j += (float) $o->get_total();
                $count_30j++;
            }
        }

        // Total all-time
        $orders_total = wc_get_orders([
            'limit'  => -1,
            'status' => ['processing', 'completed'],
            'return' => 'ids',
        ]);

        return [
            'orders_total'   => count($orders_total),
            'orders_30j'     => $count_30j,
            'revenue_30j_eur'=> round($revenue_30j, 2),
        ];
    }
}
