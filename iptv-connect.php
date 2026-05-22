<?php
/**
 * Plugin Name:       IPTV Connect
 * Plugin URI:        https://github.com/tycabane/iptv-connect-wp
 * Description:       Connecte ce site WordPress au dashboard admin IPTV central. Expose dossiers + clients + KPI + panels + audit + email templates + cron via REST API sécurisée.
 * Version:           0.9.4
 * Author:            Houssine Idsaid
 * License:           Proprietary
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Text Domain:       iptv-connect
 *
 * @package IptvConnect
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

/**
 * Lit le numéro de version directement depuis le PHPdoc du fichier plugin,
 * en utilisant file_get_contents() qui BYPASS OPcache (lit toujours le disque).
 *
 * Résout le bug "version drift" en mutualisé strict : après un upgrade
 * (wp plugin install --force), OPcache garde l'ancien bytecode en mémoire,
 * y compris l'ancienne constante de version. Avec ce mécanisme, la VRAIE
 * version (du fichier sur disque) est toujours retournée.
 *
 * Coût : 1 read filesystem (~2KB) par bootstrap du plugin. Négligeable.
 */
if (!function_exists('iptv_connect_read_version_from_file')) {
    function iptv_connect_read_version_from_file(string $file): string {
        $contents = @file_get_contents($file, false, null, 0, 2048);
        if (is_string($contents) && preg_match('/^\s*\*\s*Version:\s*([\d.]+)/m', $contents, $m)) {
            return $m[1];
        }
        return '0.0.0';
    }
}

define('IPTV_CONNECT_VERSION', iptv_connect_read_version_from_file(__FILE__));
define('IPTV_CONNECT_FILE',    __FILE__);
define('IPTV_CONNECT_DIR',     __DIR__);
define('IPTV_CONNECT_URL',     plugin_dir_url(__FILE__));
define('IPTV_CONNECT_SLUG',    'iptv-connect');

/* Hook d'activation : purge OPcache du plugin (défense en profondeur). */
register_activation_hook(__FILE__, static function (): void {
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
});

/* ─────────────────────────────────────────────────────────
   Autoload PSR-4 minimal (pas de Composer requis côté site)
   Charge les classes IptvConnect\* depuis src/
   ───────────────────────────────────────────────────────── */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'IptvConnect\\')) return;
    $relative = substr($class, strlen('IptvConnect\\'));
    $path = IPTV_CONNECT_DIR . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require_once $path;
});

/* Bootstrap du plugin (init hooks) */
add_action('plugins_loaded', static function (): void {
    \IptvConnect\Bootstrap::init();
});

/* Activation / désactivation */
register_activation_hook(__FILE__, [\IptvConnect\Bootstrap::class, 'activate']);
register_deactivation_hook(__FILE__, [\IptvConnect\Bootstrap::class, 'deactivate']);
