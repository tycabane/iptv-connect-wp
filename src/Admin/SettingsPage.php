<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Admin;

use IptvConnect\Bootstrap;

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
            <table class="form-table" role="presentation">
                <tr><th scope="row">Base URL</th><td><code><?php echo esc_html($rest_root); ?></code></td></tr>
                <tr><th scope="row">Health</th><td><code>GET <?php echo esc_html($rest_root); ?>health</code> <em>(public)</em></td></tr>
                <tr><th scope="row">Dossiers</th><td><code>GET <?php echo esc_html($rest_root); ?>dossiers</code></td></tr>
                <tr><th scope="row">Clients</th><td><code>GET <?php echo esc_html($rest_root); ?>clients</code></td></tr>
                <tr><th scope="row">KPIs</th><td><code>GET <?php echo esc_html($rest_root); ?>kpis</code></td></tr>
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
