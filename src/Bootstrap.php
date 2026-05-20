<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect;

use IptvConnect\Admin\SettingsPage;
use IptvConnect\Api\RestController;
use IptvConnect\Updater\GitHubUpdater;
use IptvConnect\Webhooks\WebhookDispatcher;

/**
 * Bootstrap — point d'entrée du plugin. Enregistre tous les hooks WP.
 */
final class Bootstrap
{
    public const OPT_API_KEY    = 'iptv_connect_api_key';
    public const OPT_INSTALLED  = 'iptv_connect_installed_at';
    public const OPT_DASHBOARD  = 'iptv_connect_dashboard_url';

    public static function init(): void
    {
        // 1. Enregistre les endpoints REST
        RestController::register();

        // 2. Page d'admin (réglages + clé API)
        if (is_admin()) {
            SettingsPage::register();
        }

        // 3. Auto-update depuis GitHub Releases
        GitHubUpdater::register();

        // 4. Dispatcher webhooks (écoute les hooks iptv_connect/* et POST vers le dashboard)
        WebhookDispatcher::register();

        // 5. Hook cron pour retry des webhooks échoués
        add_action('iptv_connect_webhook_retry', [WebhookDispatcher::class, 'retryHandler'], 10, 5);
    }

    /**
     * Activation : génère une clé API si pas déjà présente + horodatage.
     */
    public static function activate(): void
    {
        if (!get_option(self::OPT_API_KEY)) {
            update_option(self::OPT_API_KEY, self::generateApiKey());
        }
        if (!get_option(self::OPT_INSTALLED)) {
            update_option(self::OPT_INSTALLED, current_time('mysql'));
        }
        // Flush rewrite rules pour que les endpoints /wp-json/iptv-connect/v1/* répondent
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
        // Note : on NE supprime PAS la clé API ni les options à la désactivation
        // (l'admin peut réactiver et garder sa config). Pour reset complet,
        // utiliser "Supprimer" depuis l'écran Plugins → uninstall.
    }

    /**
     * Génère une clé API sécurisée : 48 chars hexadécimaux (192 bits d'entropie).
     */
    public static function generateApiKey(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function getApiKey(): string
    {
        return (string) get_option(self::OPT_API_KEY, '');
    }

    /**
     * Régénère la clé API (invalide l'ancienne). Utilisé depuis la page admin.
     */
    public static function rotateApiKey(): string
    {
        $new = self::generateApiKey();
        update_option(self::OPT_API_KEY, $new);
        return $new;
    }
}
