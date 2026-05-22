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
     *
     * Action de suppression / cancel — granulaire via 3 flags optionnels (body JSON) :
     *   - remove_dossier (bool, défaut TRUE)
     *       → supprime le post iptv_dossier + ses meta (refund NewPanel + email)
     *       → si FALSE : le dossier reste actif, seules les autres opérations
     *         (disable_renewal_cron / trash_wc_order) sont appliquées
     *   - disable_renewal_cron (bool, défaut FALSE)
     *       → set _iptv_renewal_optout=1 → le cron de renouvellement skip ce dossier
     *       → utile pour garder le service actif mais sans renouvellement auto
     *   - trash_wc_order (bool, défaut FALSE)
     *       → wp_trash_post(order_id) au lieu de update_status('cancelled')
     *       → la commande va en Corbeille WC (récupérable) au lieu d'être annulée
     *
     * Sans body : comportement legacy (remove_dossier=true + cancel WC).
     *
     * Le dossier disparaît de :
     *   - Cockpit /dossiers (si remove_dossier=true)
     *   - Cron renouvellement (si disable_renewal_cron=true OU remove_dossier=true)
     *   - WC list (si trash_wc_order=true → corbeille, sinon cancelled visible)
     *   - Espace client (si remove_dossier=true)
     *
     * Idempotent : aucun rollback si une étape échoue (best-effort), retour
     * détaillé pour audit.
     */
    public static function delete(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'iptv_dossier') {
            return new WP_Error('iptv_connect_not_found', 'Dossier introuvable', ['status' => 404]);
        }

        // Lecture des flags (body JSON optionnel)
        $body = (array) ($request->get_json_params() ?: []);
        $remove_dossier       = array_key_exists('remove_dossier', $body) ? (bool) $body['remove_dossier'] : true;
        $disable_renewal_cron = (bool) ($body['disable_renewal_cron'] ?? false);
        $trash_wc_order       = (bool) ($body['trash_wc_order'] ?? false);

        // Snapshot des refs avant suppression (le post va disparaître si remove_dossier)
        $line_id      = (int) get_post_meta($id, '_iptv_newpanel_line_id', true);
        $managed      = (int) get_post_meta($id, '_iptv_newpanel_managed', true) === 1;
        $is_simulated = (int) get_post_meta($id, '_iptv_simulated', true) === 1;
        $order_id     = (int) get_post_meta($id, '_iptv_wc_order_id', true);

        // 1. Désactive le cron de renouvellement (peut se faire sans toucher au dossier)
        $renewal_disabled = false;
        if ($disable_renewal_cron) {
            update_post_meta($id, '_iptv_renewal_optout', 1);
            update_post_meta($id, '_iptv_renewal_optout_at', current_time('mysql'));
            $renewal_disabled = true;
            if (class_exists('\\IptvCore\\Domain\\HistoryLogger')) {
                \IptvCore\Domain\HistoryLogger::log($id, '🚫 Renouvellement automatique désactivé (admin Cockpit).');
            }
        }

        // 2. Refund NewPanel (seulement si on supprime le dossier)
        $newpanel_refunded = false;
        $newpanel_error    = null;
        if ($remove_dossier && $managed && $line_id > 0 && !$is_simulated && class_exists('\\IptvCore\\Integrations\\NewPanel\\Client')) {
            try {
                $res = \IptvCore\Integrations\NewPanel\Client::refundLine($line_id);
                $newpanel_refunded = !empty($res['ok']);
                if (!$newpanel_refunded) {
                    $newpanel_error = (string) ($res['error'] ?? 'unknown');
                }
            } catch (\Throwable $e) {
                $newpanel_error = $e->getMessage();
                error_log('[iptv-connect/delete] refundLine fail (line #' . $line_id . '): ' . $e->getMessage());
            }
        }

        // 3. Traitement commande WC : trash, cancelled, ou skip
        $wc_action = 'skipped'; // 'trashed' | 'cancelled' | 'skipped' | 'already_X'
        if ($order_id > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order instanceof \WC_Order) {
                $current = $order->get_status();
                if ($trash_wc_order) {
                    // Met la commande en CORBEILLE WC (récupérable)
                    if ($current === 'trash') {
                        $wc_action = 'already_trash';
                    } else {
                        try {
                            // HPOS-compatible : $order->delete(false) → trash, true → force delete
                            $order->delete(false);
                            $wc_action = 'trashed';
                        } catch (\Throwable $e) {
                            // Fallback non-HPOS
                            wp_trash_post($order_id);
                            $wc_action = 'trashed';
                            error_log('[iptv-connect/delete] WC trash fallback wp_trash_post : ' . $e->getMessage());
                        }
                    }
                } elseif ($remove_dossier) {
                    // Pas de trash demandé mais on supprime le dossier → on annule la commande
                    if (in_array($current, ['cancelled', 'refunded', 'failed', 'trash'], true)) {
                        $wc_action = 'already_' . $current;
                    } else {
                        try {
                            $order->update_status('cancelled', sprintf(
                                '[iptv-connect] Dossier #%d supprimé · ligne NewPanel %s · commande annulée pour cohérence.',
                                $id,
                                $newpanel_refunded ? '#' . $line_id . ' refund' : ($line_id > 0 ? '#' . $line_id . ' refund_failed' : 'absente')
                            ));
                            $wc_action = 'cancelled';
                        } catch (\Throwable $e) {
                            error_log('[iptv-connect/delete] WC cancel fail (order #' . $order_id . '): ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        // 4. Suppression du post UNIQUEMENT si remove_dossier=true
        $dossier_removed = false;
        if ($remove_dossier) {
            $ok = wp_delete_post($id, true);
            if (!$ok) {
                return new WP_Error(
                    'iptv_connect_delete_failed',
                    'Échec de la suppression du dossier WP (les étapes précédentes sont déjà appliquées).',
                    ['status' => 500]
                );
            }
            $dossier_removed = true;
        }

        // 5. Audit + hook
        IptvCoreBridge::audit('DELETE_DOSSIER', 'dossier', $id, [
            'remove_dossier'      => $remove_dossier,
            'disable_renewal'     => $disable_renewal_cron,
            'trash_wc'            => $trash_wc_order,
            'newpanel_line_id'    => $line_id,
            'newpanel_refunded'   => $newpanel_refunded,
            'newpanel_error'      => $newpanel_error,
            'wc_order_id'         => $order_id,
            'wc_action'           => $wc_action,
            'simulated'           => $is_simulated,
        ]);
        do_action('iptv_connect/dossier.deleted', $id, [
            'remove_dossier'      => $remove_dossier,
            'newpanel_refunded'   => $newpanel_refunded,
            'wc_action'           => $wc_action,
            'renewal_disabled'    => $renewal_disabled,
        ]);

        // Message human-readable
        $parts = [];
        if ($dossier_removed) {
            $parts[] = 'Dossier #' . $id . ' supprimé';
        } else {
            $parts[] = 'Dossier #' . $id . ' conservé';
        }
        if ($renewal_disabled) {
            $parts[] = '🚫 renouvellement auto désactivé';
        }
        if ($managed && $line_id > 0 && $remove_dossier) {
            $parts[] = $newpanel_refunded
                ? '✅ ligne NewPanel #' . $line_id . ' refund (crédit restitué)'
                : ($is_simulated ? '🧪 ligne simulée (pas d\'appel API)' : '⚠️ refund NewPanel échoué : ' . $newpanel_error);
        }
        if ($order_id > 0) {
            switch ($wc_action) {
                case 'trashed':       $parts[] = '🗑 commande WC #' . $order_id . ' en corbeille'; break;
                case 'cancelled':     $parts[] = '✅ commande WC #' . $order_id . ' annulée'; break;
                case 'already_trash': $parts[] = 'ℹ️ commande WC #' . $order_id . ' déjà en corbeille'; break;
                default:
                    if (str_starts_with($wc_action, 'already_')) {
                        $parts[] = 'ℹ️ commande WC #' . $order_id . ' déjà ' . substr($wc_action, 8) . ' (skip)';
                    }
            }
        }

        return new WP_REST_Response([
            'ok'                 => true,
            'id'                 => $id,
            'dossier_removed'    => $dossier_removed,
            'renewal_disabled'   => $renewal_disabled,
            'newpanel_refunded'  => $newpanel_refunded,
            'newpanel_error'     => $newpanel_error,
            'wc_action'          => $wc_action,
            'message'            => implode(' · ', $parts),
        ], 200);
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
     * POST /dossiers/{id}/activate-manual
     *
     * Active un dossier avec des identifiants saisis manuellement (sans passer par
     * l'API NewPanel). Utile pour les comptes hébergés sur Golden, Smartplus, ou
     * tout panel sans API.
     *
     * Body JSON (2 formes acceptées) :
     *   A) { m3u_url: "http://host:port/get.php?username=USER&password=PASS&type=m3u_plus" }
     *      → host/user/pwd dérivés automatiquement côté serveur
     *
     *   B) { host: string, user: string, pwd: string, url?: string, port?: string }
     *      → champs fournis explicitement (mode avancé)
     *
     * Effets :
     *   - chiffre les credentials via libsodium (DossierCreds)
     *   - met _iptv_statut = "actif"
     *   - envoie l'email "actif" au client (StatusNotifier)
     *   - _iptv_newpanel_managed reste à 0 (= non géré par notre API)
     */
    public static function activateManual(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;
        $id   = (int) $request->get_param('id');
        $body = (array) $request->get_json_params();

        $post = get_post($id);
        if (!$post || $post->post_type !== 'iptv_dossier') {
            return new WP_Error('iptv_connect_not_found', 'Dossier introuvable', ['status' => 404]);
        }

        $creds_svc = IptvCoreBridge::creds();
        if (!$creds_svc) {
            return new WP_Error('iptv_connect_no_vault', 'CredsVault indisponible (IPTV_MASTER_KEY manquant ?).', ['status' => 503]);
        }

        // Parser le M3U si fourni
        $m3u_url = trim((string) ($body['m3u_url'] ?? ''));
        $parsed = [];
        if ($m3u_url !== '') {
            $parsed = self::parseM3uUrl($m3u_url);
            if ($parsed === null) {
                return new WP_Error(
                    'iptv_connect_bad_m3u',
                    'URL M3U invalide ou non-Xtream. Attendu : http://host:port/get.php?username=...&password=...',
                    ['status' => 400]
                );
            }
            $parsed['url'] = $m3u_url; // garde l'URL complète pour l'email client
        }

        // Merger explicit (body) > parsed (m3u_url)
        $host = trim((string) ($body['host'] ?? $parsed['host'] ?? ''));
        $user = trim((string) ($body['user'] ?? $parsed['user'] ?? ''));
        $pwd  = trim((string) ($body['pwd']  ?? $parsed['pwd']  ?? ''));
        $url  = trim((string) ($body['url']  ?? $parsed['url']  ?? ''));
        $port = trim((string) ($body['port'] ?? $parsed['port'] ?? ''));

        if ($host === '' || $user === '' || $pwd === '') {
            return new WP_Error(
                'iptv_connect_missing_creds',
                'host, user et pwd sont requis (soit via m3u_url, soit en clair).',
                ['status' => 400]
            );
        }

        try {
            $creds_svc->set($id, 'host', $host);
            $creds_svc->set($id, 'user', $user);
            $creds_svc->set($id, 'pwd',  $pwd);
            if ($url  !== '') $creds_svc->set($id, 'url',  $url);
            if ($port !== '') $creds_svc->set($id, 'port', $port);
        } catch (\Throwable $e) {
            return new WP_Error('iptv_connect_creds_failed', $e->getMessage(), ['status' => 500]);
        }

        // Statut → actif
        update_post_meta($id, '_iptv_statut', 'actif');
        // Marqueur explicite : ce dossier n'est PAS managé par NewPanel
        update_post_meta($id, '_iptv_newpanel_managed', 0);

        // Email client avec ses credentials (template iptv-core)
        if (class_exists('\\IptvCore\\Email\\StatusNotifier')) {
            try {
                \IptvCore\Email\StatusNotifier::send($id, 'actif');
            } catch (\Throwable $e) {
                // best-effort ; on log mais on ne bloque pas l'activation
                error_log('[iptv-connect] activate-manual: email client failed: ' . $e->getMessage());
            }
        }

        // History log si dispo
        if (class_exists('\\IptvCore\\Domain\\HistoryLogger')) {
            \IptvCore\Domain\HistoryLogger::log(
                $id,
                sprintf('✏️ Activation manuelle (saisie admin) · host=%s · user=%s', $host, $user)
            );
        }

        // Sync commande WC → "Completed" (l'activation marque la livraison de l'abonnement)
        if (class_exists('\\IptvCore\\Admin\\Cockpit\\AjaxHandlers')) {
            try {
                \IptvCore\Admin\Cockpit\AjaxHandlers::syncWcOrderToCompleted(
                    $id,
                    sprintf('saisie manuelle · host=%s', $host)
                );
            } catch (\Throwable $e) {
                error_log('[iptv-connect] activate-manual: WC sync failed: ' . $e->getMessage());
            }
        }

        IptvCoreBridge::audit('ACTIVATE_MANUAL', 'dossier', $id, [
            'host'      => $host,
            'from_m3u'  => $m3u_url !== '',
        ]);
        do_action('iptv_connect/dossier.activated_manual', $id, ['host' => $host, 'user' => $user]);

        return new WP_REST_Response([
            'ok'     => true,
            'id'     => $id,
            'host'   => $host,
            'user'   => $user,
            'statut' => 'actif',
            'message' => 'Dossier activé (manuel) · email client envoyé.',
        ], 200);
    }

    /**
     * Parse une URL M3U Xtream et extrait host/user/pwd/port.
     * Retourne null si format invalide.
     *
     * Format attendu : http(s)://host[:port]/get.php?username=USER&password=PASS[&type=m3u_plus]
     *
     * @return array{host:string,user:string,pwd:string,port:string}|null
     */
    private static function parseM3uUrl(string $raw): ?array
    {
        $parts = @parse_url($raw);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['query'])) {
            return null;
        }
        parse_str($parts['query'], $qs);
        $user = (string) ($qs['username'] ?? '');
        $pwd  = (string) ($qs['password'] ?? '');
        if ($user === '' || $pwd === '') return null;

        $scheme = (string) ($parts['scheme'] ?? 'http');
        $port   = isset($parts['port']) ? (string) $parts['port'] : '';
        $host   = $scheme . '://' . $parts['host'] . ($port !== '' ? ':' . $port : '');

        return ['host' => $host, 'user' => $user, 'pwd' => $pwd, 'port' => $port];
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
            'simulated'      => (int)   get_post_meta($id, '_iptv_simulated', true) === 1,
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
