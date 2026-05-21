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
 * DossiersEndpoint
 *
 * GET    /dossiers                         · liste paginée
 * GET    /dossiers/{id}                    · détail (+ credentials optionnels)
 * POST   /dossiers                         · créer un dossier
 * PUT    /dossiers/{id}                    · mettre à jour les champs / métas / statut
 * DELETE /dossiers/{id}                    · supprimer (force=true)
 * POST   /dossiers/{id}/renew              · renouveler (provisioning NewPanel ou manuel)
 * POST   /dossiers/{id}/provision          · activer sur NewPanel
 * POST   /dossiers/{id}/migrate-host       · migrer host
 * POST   /dossiers/{id}/credentials/rotate · rotation des credentials
 *
 * Compatible avec ou sans iptv-core :
 *   - Lecture : si CPT iptv_dossier existe → lit directement ; sinon fallback WC orders
 *   - Écriture : requiert iptv-core (sinon 503)
 */
final class DossiersEndpoint
{
    /* ─────────────────────────────────────────────────────
       Lecture
       ───────────────────────────────────────────────────── */

    public static function list(WP_REST_Request $request): WP_REST_Response
    {
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = max(1, min(200, (int) $request->get_param('per_page')));
        $search   = sanitize_text_field((string) $request->get_param('search'));
        $status   = sanitize_text_field((string) $request->get_param('status'));
        $since    = sanitize_text_field((string) $request->get_param('since')); // ISO 8601

        if (post_type_exists('iptv_dossier')) {
            return self::listFromIptvCore($page, $per_page, $search, $status, $since);
        }
        if (class_exists('WooCommerce')) {
            return self::listFromWcOrders($page, $per_page, $search, $status, $since);
        }
        return new WP_REST_Response([
            'items' => [], 'total' => 0, 'page' => $page,
            'note' => 'Aucun système de dossiers détecté (ni iptv-core, ni WooCommerce).',
        ], 200);
    }

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
            if ($inc) {
                IptvCoreBridge::audit('VIEW_CREDS', 'dossier', $id, ['source' => 'api']);
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
       Écriture (requiert iptv-core)
       ───────────────────────────────────────────────────── */

    /**
     * POST /dossiers
     * Body JSON : { email, formule, duree_mois, prix_eur, ecrans, host?, user_iptv?, pwd?, port?, url?, statut?, exp_date? }
     */
    public static function create(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;

        $body = (array) $request->get_json_params();
        $email = sanitize_email((string) ($body['email'] ?? ''));
        if (!$email) {
            return new WP_Error('iptv_connect_bad_email', 'Email requis et valide.', ['status' => 400]);
        }

        $post_id = wp_insert_post([
            'post_type'   => 'iptv_dossier',
            'post_status' => 'publish',
            'post_title'  => $email,
        ], true);
        if (is_wp_error($post_id) || !$post_id) {
            return new WP_Error('iptv_connect_create_failed', 'wp_insert_post a échoué.', ['status' => 500]);
        }

        // Méta business
        self::applyDossierMeta((int) $post_id, $body);

        // Credentials chiffrés si présents
        $creds = IptvCoreBridge::creds();
        if ($creds) {
            foreach (['host', 'port', 'user' => 'user_iptv', 'pwd', 'url'] as $field => $bodyKey) {
                $key   = is_int($field) ? $bodyKey : $field;
                $value = (string) ($body[$bodyKey] ?? '');
                if ($value !== '') {
                    try { $creds->set((int) $post_id, $key, $value); } catch (\Throwable $e) {}
                }
            }
        }

        IptvCoreBridge::audit('CREATE_DOSSIER', 'dossier', (int) $post_id, ['email' => $email]);
        do_action('iptv_connect/dossier.created', (int) $post_id, $body);

        $post = get_post((int) $post_id);
        return new WP_REST_Response(self::serializeIptvDossier($post, false), 201);
    }

    /**
     * PUT /dossiers/{id}
     * Body JSON : tout champ modifiable (métas business + statut + exp_date)
     */
    public static function update(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'iptv_dossier') {
            return new WP_Error('iptv_connect_not_found', 'Dossier introuvable', ['status' => 404]);
        }

        $body = (array) $request->get_json_params();
        self::applyDossierMeta($id, $body);

        IptvCoreBridge::audit('EDIT_DOSSIER', 'dossier', $id, ['fields' => array_keys($body)]);
        do_action('iptv_connect/dossier.updated', $id, $body);

        return new WP_REST_Response(self::serializeIptvDossier(get_post($id), false), 200);
    }

    /**
     * DELETE /dossiers/{id}
     */
    public static function delete(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'iptv_dossier') {
            return new WP_Error('iptv_connect_not_found', 'Dossier introuvable', ['status' => 404]);
        }

        $ok = wp_delete_post($id, true);
        if (!$ok) {
            return new WP_Error('iptv_connect_delete_failed', 'Échec de la suppression.', ['status' => 500]);
        }

        IptvCoreBridge::audit('DELETE_DOSSIER', 'dossier', $id);
        do_action('iptv_connect/dossier.deleted', $id);
        return new WP_REST_Response(['ok' => true, 'id' => $id], 200);
    }

    /**
     * POST /dossiers/{id}/renew
     * Body : { duree_mois?: int } (0 = utiliser la durée d'origine du dossier)
     */
    public static function renew(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;
        $id = (int) $request->get_param('id');
        $months = (int) ($request->get_json_params()['duree_mois'] ?? 0);

        if (!class_exists('\\IptvCore\\Integrations\\NewPanel\\Provisioning')) {
            return new WP_Error('iptv_connect_no_provisioning', 'Module Provisioning indisponible.', ['status' => 503]);
        }

        try {
            $result = (new \IptvCore\Integrations\NewPanel\Provisioning())->extend($id, $months);
        } catch (\Throwable $e) {
            return new WP_Error('iptv_connect_renew_exception', $e->getMessage(), ['status' => 500]);
        }

        $ok = !empty($result['ok']);
        $status = $ok ? 200 : 422;
        IptvCoreBridge::audit('RENEW_DOSSIER', 'dossier', $id, ['months' => $months, 'ok' => $ok]);

        // Hooks conditionnels : ne pas tirer `dossier.renewed` (= succès, écoute Telegram)
        // si l'API a refusé l'opération. Sinon les abonnés (notifications, etc.) annoncent
        // un renouvellement qui n'a jamais eu lieu.
        if ($ok) {
            do_action('iptv_connect/dossier.renewed', $id, $months, $result);
        } else {
            do_action('iptv_connect/dossier.renew_failed', $id, $months, $result);
        }
        return new WP_REST_Response($result, $status);
    }

    /**
     * POST /dossiers/{id}/provision  (active sur NewPanel)
     */
    public static function provision(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;
        $id = (int) $request->get_param('id');

        if (!class_exists('\\IptvCore\\Integrations\\NewPanel\\Provisioning')) {
            return new WP_Error('iptv_connect_no_provisioning', 'Module Provisioning indisponible.', ['status' => 503]);
        }

        try {
            $result = (new \IptvCore\Integrations\NewPanel\Provisioning())->activate($id);
        } catch (\Throwable $e) {
            return new WP_Error('iptv_connect_provision_exception', $e->getMessage(), ['status' => 500]);
        }

        $ok = !empty($result['ok']);
        $status = $ok ? 200 : 422;
        IptvCoreBridge::audit('PROVISION_DOSSIER', 'dossier', $id, ['ok' => $ok]);

        // Hooks conditionnels (cf. renew() ci-dessus)
        if ($ok) {
            do_action('iptv_connect/dossier.provisioned', $id, $result);
        } else {
            do_action('iptv_connect/dossier.provision_failed', $id, $result);
        }
        return new WP_REST_Response($result, $status);
    }

    /**
     * POST /dossiers/{id}/migrate-host
     * Body : { new_host: string, new_user?: string, new_pwd?: string, new_port?: string, new_url?: string }
     */
    public static function migrateHost(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;
        $id   = (int) $request->get_param('id');
        $body = (array) $request->get_json_params();

        $new_host = trim((string) ($body['new_host'] ?? ''));
        if ($new_host === '') {
            return new WP_Error('iptv_connect_bad_host', 'new_host requis.', ['status' => 400]);
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== 'iptv_dossier') {
            return new WP_Error('iptv_connect_not_found', 'Dossier introuvable', ['status' => 404]);
        }

        $creds = IptvCoreBridge::creds();
        if (!$creds) {
            return new WP_Error('iptv_connect_no_vault', 'CredsVault indisponible (IPTV_MASTER_KEY manquant ?).', ['status' => 503]);
        }

        $old_host = (string) get_post_meta($id, '_iptv_creds_host_clear', true);
        try {
            $creds->set($id, 'host', $new_host);
            foreach (['user' => 'new_user', 'pwd' => 'new_pwd', 'port' => 'new_port', 'url' => 'new_url'] as $field => $key) {
                if (!empty($body[$key])) $creds->set($id, $field, (string) $body[$key]);
            }
        } catch (\Throwable $e) {
            return new WP_Error('iptv_connect_migrate_failed', $e->getMessage(), ['status' => 500]);
        }

        IptvCoreBridge::audit('MIGRATE_CREDS', 'dossier', $id, ['old_host' => $old_host, 'new_host' => $new_host]);
        do_action('iptv_connect/dossier.host_migrated', $id, $old_host, $new_host);
        return new WP_REST_Response([
            'ok' => true, 'id' => $id, 'old_host' => $old_host, 'new_host' => $new_host,
        ], 200);
    }

    /**
     * POST /dossiers/{id}/credentials/rotate
     * Body : { field: host|port|user|pwd|url, value: string }
     */
    public static function rotateCredentials(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;
        $id    = (int) $request->get_param('id');
        $body  = (array) $request->get_json_params();
        $field = strtolower(trim((string) ($body['field'] ?? '')));
        $value = (string) ($body['value'] ?? '');

        if (!in_array($field, ['host', 'port', 'user', 'pwd', 'url'], true)) {
            return new WP_Error('iptv_connect_bad_field', 'field doit être host|port|user|pwd|url.', ['status' => 400]);
        }
        if ($value === '') {
            return new WP_Error('iptv_connect_bad_value', 'value est requis.', ['status' => 400]);
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== 'iptv_dossier') {
            return new WP_Error('iptv_connect_not_found', 'Dossier introuvable', ['status' => 404]);
        }

        $creds = IptvCoreBridge::creds();
        if (!$creds) {
            return new WP_Error('iptv_connect_no_vault', 'CredsVault indisponible.', ['status' => 503]);
        }

        try {
            $creds->set($id, $field, $value);
        } catch (\Throwable $e) {
            return new WP_Error('iptv_connect_rotate_failed', $e->getMessage(), ['status' => 500]);
        }

        IptvCoreBridge::audit('ROTATE_KEY', 'dossier', $id, ['field' => $field]);
        do_action('iptv_connect/credentials.rotated', $id, $field);
        return new WP_REST_Response(['ok' => true, 'id' => $id, 'field' => $field], 200);
    }

    /* ─────────────────────────────────────────────────────
       Helpers
       ───────────────────────────────────────────────────── */

    /**
     * Applique les méta business sur un dossier (ignore les credentials).
     * Mapping body → meta key.
     */
    private static function applyDossierMeta(int $id, array $body): void
    {
        $map = [
            'email'           => '_iptv_client_email',
            'user_id'         => '_iptv_client_user_id',
            'formule'         => '_iptv_formule',
            'duree_mois'      => '_iptv_duree_mois',
            'ecrans'          => '_iptv_ecrans',
            'prix_eur'        => '_iptv_prix',
            'statut'          => '_iptv_statut',
            'exp_date'        => '_iptv_date_expiration',
            'mode_discret'    => '_iptv_mode_discret',
            'notes_admin'     => '_iptv_notes_admin',
            'wc_order_id'     => '_iptv_wc_order_id',
            'newpanel_line'   => '_iptv_newpanel_line_id',
            'newpanel_managed'=> '_iptv_newpanel_managed',
            'renewal_optout'  => '_iptv_renewal_optout',
        ];
        foreach ($map as $bk => $mk) {
            if (array_key_exists($bk, $body)) {
                $val = $body[$bk];
                if (is_string($val)) $val = sanitize_text_field($val);
                update_post_meta($id, $mk, $val);
            }
        }
    }

    /* ─────────────────────────────────────────────────────
       Source 1 : iptv-core
       ───────────────────────────────────────────────────── */

    private static function listFromIptvCore(int $page, int $per_page, string $search, string $status, string $since = ''): WP_REST_Response
    {
        $args = [
            'post_type'      => 'iptv_dossier',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ];
        if ($status !== '') {
            $args['meta_query'] = [['key' => '_iptv_statut', 'value' => $status]];
        }
        if ($search !== '') {
            $args['s'] = $search;
        }
        // Sync incrémentale : ne retourne que les dossiers modifiés depuis $since
        if ($since !== '' && ($ts = strtotime($since)) !== false) {
            $args['date_query'] = [[
                'column'    => 'post_modified_gmt',
                'after'     => gmdate('Y-m-d H:i:s', $ts),
                'inclusive' => true,
            ]];
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
       Source 2 : WooCommerce (fallback lecture seule)
       ───────────────────────────────────────────────────── */

    private static function listFromWcOrders(int $page, int $per_page, string $search, string $status, string $since = ''): WP_REST_Response
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
        if ($since !== '' && ($ts = strtotime($since)) !== false) {
            $args['date_modified'] = '>=' . gmdate('Y-m-d H:i:s', $ts);
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
