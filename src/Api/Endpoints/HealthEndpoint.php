<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Api\Endpoints;

use WP_REST_Request;
use WP_REST_Response;

/**
 * HealthEndpoint — GET /health
 *
 * Publique. Retourne :
 *   - ping + version plugin + version API
 *   - capabilities[] : liste des features supportées (anti schema-drift)
 *   - features : flags des stacks détectées (iptv-core, WC, NewPanel, etc.)
 *
 * Le dashboard utilise capabilities[] pour brancher conditionnellement
 * les endpoints disponibles selon la version du plugin installée.
 */
final class HealthEndpoint
{
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $has_core = post_type_exists('iptv_dossier') && class_exists('\\IptvCore\\Security\\CredsVault');
        $has_wc   = class_exists('WooCommerce');

        return new WP_REST_Response([
            'ok'             => true,
            'plugin_version' => defined('IPTV_CONNECT_VERSION') ? IPTV_CONNECT_VERSION : 'unknown',
            'api_version'    => 'v1',
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'site_url'       => home_url('/'),
            'site_name'      => get_bloginfo('name'),
            'capabilities'   => self::computeCapabilities($has_core, $has_wc),
            'features'       => [
                'iptv_core'    => $has_core,
                'woocommerce'  => $has_wc,
                'newpanel'     => class_exists('\\IptvCore\\Integrations\\NewPanel\\Provisioning'),
                'audit_log'    => class_exists('\\IptvCore\\Security\\AuditLogger'),
                'email_engine' => class_exists('\\IptvCore\\Email\\TemplateEngine'),
            ],
            'time'           => current_time('mysql'),
            'timestamp'      => time(),
        ], 200);
    }

    /**
     * Compose la liste des capabilities supportées par cette install.
     * Le dashboard externe se base là-dessus, jamais sur plugin_version.
     */
    private static function computeCapabilities(bool $has_core, bool $has_wc): array
    {
        $caps = [
            'health',
            'kpis',
            'sync.incremental',          // ?since=ISO8601 supporté en v0.3+
            'clients.read',
            'email-templates.read',
            'email-templates.write',
            'cron.renewal.read',
            'cron.renewal.write',
        ];

        // Lecture des dossiers : dispo via iptv-core OU WC fallback
        if ($has_core || $has_wc) {
            $caps[] = 'dossiers.read';
        }

        // Écriture dossiers + actions : requiert iptv-core
        if ($has_core) {
            array_push($caps,
                'dossiers.write',
                'dossiers.delete',
                'dossiers.renew',
                'dossiers.provision',
                'dossiers.migrate-host',
                'credentials.read',
                'credentials.rotate',
                'panels.read',
                'audit-log.read'
            );
        }

        return $caps;
    }
}
