<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Updater;

/**
 * GitHubUpdater — auto-update depuis GitHub Releases
 *
 * Vérifie la dernière release du repo et compare au tag local.
 * Si une nouvelle version existe, propose la mise à jour depuis l'écran Plugins de WP.
 *
 * Repo : tycabane/iptv-connect-wp (privé)
 * Asset attendu dans la release : iptv-connect.zip
 *
 * Implémentation native (sans dépendance composer) car les sites clients
 * n'exécutent pas `composer install`.
 */
final class GitHubUpdater
{
    private const REPO        = 'tycabane/iptv-connect-wp';
    private const TRANSIENT   = 'iptv_connect_gh_release';
    private const CACHE_HOURS = 6;

    public static function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'checkForUpdate']);
        add_filter('plugins_api', [self::class, 'pluginsApi'], 10, 3);
        add_filter('upgrader_post_install', [self::class, 'postInstall'], 10, 3);
    }

    /**
     * Hook principal : injecté dans la liste des plugins à mettre à jour.
     */
    public static function checkForUpdate($transient)
    {
        if (empty($transient) || !is_object($transient)) return $transient;
        if (!isset($transient->checked)) return $transient;

        $current = self::currentVersion();
        $release = self::fetchLatestRelease();

        if (!$release || empty($release['tag_name'])) return $transient;

        $remote_version = ltrim((string) $release['tag_name'], 'v');
        if (version_compare($remote_version, $current, '<=')) return $transient;

        // Cherche l'asset .zip
        $zip_url = null;
        foreach (($release['assets'] ?? []) as $asset) {
            if (isset($asset['name'], $asset['browser_download_url']) && substr($asset['name'], -4) === '.zip') {
                $zip_url = $asset['browser_download_url'];
                break;
            }
        }
        if (!$zip_url) {
            // Fallback : tarball auto-généré par GitHub
            $zip_url = $release['zipball_url'] ?? null;
        }
        if (!$zip_url) return $transient;

        $plugin_file = self::pluginFile();

        $transient->response[$plugin_file] = (object) [
            'slug'         => IPTV_CONNECT_SLUG,
            'plugin'       => $plugin_file,
            'new_version'  => $remote_version,
            'url'          => 'https://github.com/' . self::REPO,
            'package'      => $zip_url,
            'tested'       => get_bloginfo('version'),
            'requires_php' => '8.0',
        ];

        return $transient;
    }

    /**
     * Affiche les détails du plugin dans la modale "Voir les détails".
     */
    public static function pluginsApi($result, $action, $args)
    {
        if ($action !== 'plugin_information') return $result;
        if (!isset($args->slug) || $args->slug !== IPTV_CONNECT_SLUG) return $result;

        $release = self::fetchLatestRelease();
        if (!$release) return $result;

        return (object) [
            'name'         => 'IPTV Connect',
            'slug'         => IPTV_CONNECT_SLUG,
            'version'      => ltrim((string) ($release['tag_name'] ?? ''), 'v'),
            'author'       => 'AZ Services',
            'homepage'     => 'https://github.com/' . self::REPO,
            'requires'     => '6.0',
            'requires_php' => '8.0',
            'tested'       => get_bloginfo('version'),
            'sections'     => [
                'description' => 'Connecteur REST sécurisé pour dashboard admin multi-site.',
                'changelog'   => '<pre>' . esc_html((string) ($release['body'] ?? 'Aucune note de version.')) . '</pre>',
            ],
            'download_link' => $release['assets'][0]['browser_download_url'] ?? $release['zipball_url'] ?? '',
        ];
    }

    /**
     * Après installation : renomme le dossier extrait au slug attendu.
     * (Les zipballs GitHub contiennent un dossier nommé `owner-repo-sha`.)
     */
    public static function postInstall($response, $hook_extra, $result)
    {
        global $wp_filesystem;

        $plugin_file = self::pluginFile();
        $target      = WP_PLUGIN_DIR . '/' . dirname($plugin_file);

        if (isset($result['destination']) && $result['destination'] !== $target) {
            $wp_filesystem->move($result['destination'], $target);
            $result['destination']      = $target;
            $result['destination_name'] = dirname($plugin_file);
        }

        if (is_plugin_active($plugin_file)) {
            activate_plugin($plugin_file);
        }
        return $response;
    }

    /* ────────────────── helpers ────────────────── */

    private static function currentVersion(): string
    {
        return defined('IPTV_CONNECT_VERSION') ? IPTV_CONNECT_VERSION : '0.0.0';
    }

    private static function pluginFile(): string
    {
        // ex: iptv-connect/iptv-connect.php
        return IPTV_CONNECT_SLUG . '/' . IPTV_CONNECT_SLUG . '.php';
    }

    /**
     * Récupère la dernière release GitHub (avec cache 6h via transient).
     *
     * @return array<string,mixed>|null
     */
    private static function fetchLatestRelease(): ?array
    {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached)) return $cached;

        $url = sprintf('https://api.github.com/repos/%s/releases/latest', self::REPO);

        $args = [
            'timeout' => 8,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'iptv-connect-wp',
            ],
        ];

        // Si le repo est privé, l'admin peut définir IPTV_CONNECT_GH_TOKEN dans wp-config.php
        if (defined('IPTV_CONNECT_GH_TOKEN') && IPTV_CONNECT_GH_TOKEN) {
            $args['headers']['Authorization'] = 'Bearer ' . IPTV_CONNECT_GH_TOKEN;
        }

        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) return null;
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return null;

        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($body)) return null;

        set_transient(self::TRANSIENT, $body, self::CACHE_HOURS * HOUR_IN_SECONDS);
        return $body;
    }
}
