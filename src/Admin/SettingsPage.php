<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Admin;

use IptvConnect\Bootstrap;
use IptvConnect\Webhooks\WebhookDispatcher;

/**
 * SettingsPage — page admin "🔌 Dashboard Connect"
 *
 * Affiche la clé API + bouton rotation + URL dashboard.
 * Accessible : Réglages → 🔌 Dashboard Connect (capability: manage_options)
 */
final class SettingsPage
{
    public const PAGE_SLUG = 'iptv-connect';
    public const NONCE_ROTATE = 'iptv_connect_rotate';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'handleActions']);
    }

    public static function addMenu(): void
    {
        add_options_page(
            'Dashboard Connect',
            '🔌 Dashboard Connect',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function handleActions(): void
    {
        if (!current_user_can('manage_options')) return;
        if (empty($_POST['iptv_connect_action'])) return;

        $action = sanitize_text_field((string) $_POST['iptv_connect_action']);

        if ($action === 'rotate' && check_admin_referer(self::NONCE_ROTATE)) {
            Bootstrap::rotateApiKey();
            wp_safe_redirect(add_query_arg(['rotated' => '1'], admin_url('options-general.php?page=' . self::PAGE_SLUG)));
            exit;
        }

        if ($action === 'save_dashboard_url' && check_admin_referer(self::NONCE_ROTATE)) {
            $url = esc_url_raw((string) ($_POST['dashboard_url'] ?? ''));
            update_option(Bootstrap::OPT_DASHBOARD, $url);
            wp_safe_redirect(add_query_arg(['saved' => '1'], admin_url('options-general.php?page=' . self::PAGE_SLUG)));
            exit;
        }

        if ($action === 'save_webhooks' && check_admin_referer(self::NONCE_ROTATE)) {
            $wh_url    = esc_url_raw((string) ($_POST['webhook_url'] ?? ''));
            $wh_secret = trim((string) ($_POST['webhook_secret'] ?? ''));
            update_option(WebhookDispatcher::OPT_URL, $wh_url);
            update_option(WebhookDispatcher::OPT_SECRET, $wh_secret);
            wp_safe_redirect(add_query_arg(['webhooks_saved' => '1'], admin_url('options-general.php?page=' . self::PAGE_SLUG)));
            exit;
        }

        if ($action === 'rotate_webhook_secret' && check_admin_referer(self::NONCE_ROTATE)) {
            $new = bin2hex(random_bytes(32));
            update_option(WebhookDispatcher::OPT_SECRET, $new);
            wp_safe_redirect(add_query_arg(['webhook_secret_rotated' => '1'], admin_url('options-general.php?page=' . self::PAGE_SLUG)));
            exit;
        }

        if ($action === 'test_webhook' && check_admin_referer(self::NONCE_ROTATE)) {
            do_action('iptv_connect/dossier.created', 0, ['email' => 'test@webhook.local', 'test' => true]);
            wp_safe_redirect(add_query_arg(['webhook_tested' => '1'], admin_url('options-general.php?page=' . self::PAGE_SLUG)));
            exit;
        }
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) return;

        $api_key   = Bootstrap::getApiKey();
        $dash_url  = (string) get_option(Bootstrap::OPT_DASHBOARD, '');
        $installed = (string) get_option(Bootstrap::OPT_INSTALLED, '');
        $rest_root = rest_url(\IptvConnect\Api\RestController::NAMESPACE . '/');
        ?>
        <div class="wrap">
            <h1>🔌 Dashboard Connect</h1>
            <p>Ce plugin expose les données du site (dossiers, clients, KPIs) vers un dashboard externe via une API REST sécurisée par clé Bearer.</p>

            <?php if (!empty($_GET['rotated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Nouvelle clé API générée. <strong>L'ancienne ne fonctionne plus.</strong></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible"><p>URL du dashboard enregistrée.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['webhooks_saved'])): ?>
                <div class="notice notice-success is-dismissible"><p>Configuration webhooks enregistrée.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['webhook_secret_rotated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Nouveau secret webhook généré. <strong>Mettez à jour le dashboard avec la nouvelle valeur.</strong></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['webhook_tested'])): ?>
                <div class="notice notice-info is-dismissible"><p>Webhook de test envoyé (event <code>dossier.created</code> avec data <code>test: true</code>). Vérifiez la réception côté dashboard.</p></div>
            <?php endif; ?>

            <h2>Clé API</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Clé actuelle</th>
                    <td>
                        <code style="user-select:all; padding:6px 10px; background:#f0f0f1; border-radius:4px; font-size:13px;"><?php echo esc_html($api_key); ?></code>
                        <p class="description">À copier dans le header <code>Authorization: Bearer ...</code> côté dashboard.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Régénérer</th>
                    <td>
                        <form method="post" onsubmit="return confirm('Régénérer la clé ? L\'ancienne sera invalidée immédiatement.');">
                            <?php wp_nonce_field(self::NONCE_ROTATE); ?>
                            <input type="hidden" name="iptv_connect_action" value="rotate">
                            <button type="submit" class="button button-secondary">🔄 Régénérer la clé</button>
                        </form>
                    </td>
                </tr>
            </table>

            <h2>Endpoints REST</h2>
            <p class="description">Base URL : <code><?php echo esc_html($rest_root); ?></code></p>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Health (public)</th><td><code>GET /health</code></td></tr>
                <tr><th scope="row">KPIs</th><td><code>GET /kpis</code></td></tr>
                <tr><th scope="row">Dossiers</th><td>
                    <code>GET /dossiers</code> · <code>POST /dossiers</code><br>
                    <code>GET|PUT|DELETE /dossiers/{id}</code><br>
                    <code>POST /dossiers/{id}/renew</code><br>
                    <code>POST /dossiers/{id}/provision</code><br>
                    <code>POST /dossiers/{id}/migrate-host</code><br>
                    <code>POST /dossiers/{id}/credentials/rotate</code>
                </td></tr>
                <tr><th scope="row">Clients</th><td><code>GET /clients</code></td></tr>
                <tr><th scope="row">Panels IPTV</th><td><code>GET /panels</code> <em>(requiert iptv-core)</em></td></tr>
                <tr><th scope="row">Audit log</th><td><code>GET /audit-log</code> <em>(requiert iptv-core)</em></td></tr>
                <tr><th scope="row">Email templates</th><td><code>GET|PUT /email-templates</code></td></tr>
                <tr><th scope="row">Cron renouvellement</th><td><code>GET|PUT /cron/renewal</code></td></tr>
            </table>

            <h2>Dashboard externe</h2>
            <form method="post">
                <?php wp_nonce_field(self::NONCE_ROTATE); ?>
                <input type="hidden" name="iptv_connect_action" value="save_dashboard_url">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="dashboard_url">URL du dashboard</label></th>
                        <td>
                            <input type="url" id="dashboard_url" name="dashboard_url" class="regular-text" value="<?php echo esc_attr($dash_url); ?>" placeholder="https://admin.example.com">
                            <p class="description">URL où votre dashboard externe est déployé (facultatif, juste pour mémoire).</p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary">Enregistrer</button></p>
            </form>

            <h2>Webhooks sortants (temps réel)</h2>
            <p class="description">
                Quand un événement se produit (dossier créé, renouvelé, etc.), iptv-connect POST automatiquement vers
                l'URL configurée avec une signature HMAC SHA-256. Plus rapide que le polling 60 s du dashboard.
            </p>
            <?php $wh_url = (string) get_option(\IptvConnect\Webhooks\WebhookDispatcher::OPT_URL, ''); ?>
            <?php $wh_secret = (string) get_option(\IptvConnect\Webhooks\WebhookDispatcher::OPT_SECRET, ''); ?>
            <form method="post">
                <?php wp_nonce_field(self::NONCE_ROTATE); ?>
                <input type="hidden" name="iptv_connect_action" value="save_webhooks">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="webhook_url">URL réceptrice</label></th>
                        <td>
                            <input type="url" id="webhook_url" name="webhook_url" class="regular-text" value="<?php echo esc_attr($wh_url); ?>" placeholder="https://iptv-admin.vercel.app/api/wh/wp">
                            <p class="description">Endpoint du dashboard qui reçoit les events. Laisser vide pour désactiver.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webhook_secret">Secret HMAC</label></th>
                        <td>
                            <input type="text" id="webhook_secret" name="webhook_secret" class="regular-text" value="<?php echo esc_attr($wh_secret); ?>" placeholder="(secret partagé)">
                            <p class="description">Doit être identique à <code>WEBHOOK_SECRET</code> côté dashboard.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary">Enregistrer webhooks</button>
                </p>
            </form>

            <p>
                <form method="post" style="display:inline-block; margin-right:8px;" onsubmit="return confirm('Générer un nouveau secret ? L\'ancien sera invalidé.');">
                    <?php wp_nonce_field(self::NONCE_ROTATE); ?>
                    <input type="hidden" name="iptv_connect_action" value="rotate_webhook_secret">
                    <button type="submit" class="button button-secondary">🔄 Régénérer le secret</button>
                </form>
                <form method="post" style="display:inline-block;">
                    <?php wp_nonce_field(self::NONCE_ROTATE); ?>
                    <input type="hidden" name="iptv_connect_action" value="test_webhook">
                    <button type="submit" class="button button-secondary" <?php disabled($wh_url === '' || $wh_secret === ''); ?>>🧪 Envoyer un test</button>
                </form>
            </p>

            <h2>Informations</h2>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Version du plugin</th><td><?php echo esc_html(defined('IPTV_CONNECT_VERSION') ? IPTV_CONNECT_VERSION : '?'); ?></td></tr>
                <tr><th scope="row">Installé le</th><td><?php echo esc_html($installed ?: '—'); ?></td></tr>
                <tr><th scope="row">iptv-core détecté</th><td><?php echo post_type_exists('iptv_dossier') ? '✅ Oui' : '⚠️ Non (mode WC fallback)'; ?></td></tr>
                <tr><th scope="row">WooCommerce détecté</th><td><?php echo class_exists('WooCommerce') ? '✅ Oui' : '❌ Non'; ?></td></tr>
            </table>

            <h2>Test rapide (curl)</h2>
            <pre style="background:#1d1d1f; color:#e8e8ed; padding:14px; border-radius:6px; overflow:auto;">curl -H "Authorization: Bearer <?php echo esc_html($api_key); ?>" \
     "<?php echo esc_html($rest_root); ?>kpis"</pre>
        </div>
        <?php
    }
}
