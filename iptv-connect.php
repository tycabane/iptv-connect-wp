<?php
/**
 * Plugin Name:       IPTV Connect
 * Plugin URI:        https://github.com/tycabane/iptv-connect-wp
 * Description:       Connecte ce site WordPress au dashboard admin IPTV central. Expose dossiers + clients + KPI + panels + audit + email templates + cron via REST API sécurisée.
 * Version:           0.7.0
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

define('IPTV_CONNECT_VERSION', '0.7.0');
define('IPTV_CONNECT_FILE',    __FILE__);
define('IPTV_CONNECT_DIR',     __DIR__);
define('IPTV_CONNECT_URL',     plugin_dir_url(__FILE__));
define('IPTV_CONNECT_SLUG',    'iptv-connect');

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
