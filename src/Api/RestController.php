<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Api;

use IptvConnect\Api\Endpoints\AuditLogEndpoint;
use IptvConnect\Api\Endpoints\ClientsEndpoint;
use IptvConnect\Api\Endpoints\CronEndpoint;
use IptvConnect\Api\Endpoints\DossiersEndpoint;
use IptvConnect\Api\Endpoints\EmailTemplatesEndpoint;
use IptvConnect\Api\Endpoints\HealthEndpoint;
use IptvConnect\Api\Endpoints\KpisEndpoint;
use IptvConnect\Api\Endpoints\PanelsEndpoint;
use IptvConnect\Api\Endpoints\UrlHealthEndpoint;
use IptvConnect\Auth\BearerToken;

/**
 * RestController — enregistre les routes /wp-json/iptv-connect/v1/*
 *
 * Toutes les routes utilisent BearerToken::check comme permission_callback,
 * sauf /health qui est publique.
 */
final class RestController
{
    public const NAMESPACE = 'iptv-connect/v1';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        $auth = [BearerToken::class, 'check'];

        // ─── Health (publique) ───
        register_rest_route(self::NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => [HealthEndpoint::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);

        // ─── KPIs ───
        register_rest_route(self::NAMESPACE, '/kpis', [
            'methods'             => 'GET',
            'callback'            => [KpisEndpoint::class, 'handle'],
            'permission_callback' => $auth,
        ]);

        // ─── Dossiers (lecture) ───
        register_rest_route(self::NAMESPACE, '/dossiers', [
            [
                'methods'             => 'GET',
                'callback'            => [DossiersEndpoint::class, 'list'],
                'permission_callback' => $auth,
                'args' => [
                    'page'     => ['type' => 'integer', 'default' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 50],
                    'search'   => ['type' => 'string',  'default' => ''],
                    'status'   => ['type' => 'string',  'default' => ''],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [DossiersEndpoint::class, 'create'],
                'permission_callback' => $auth,
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/dossiers/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [DossiersEndpoint::class, 'get'],
                'permission_callback' => $auth,
                'args' => [
                    'include_credentials' => ['type' => 'boolean', 'default' => false],
                ],
            ],
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [DossiersEndpoint::class, 'update'],
                'permission_callback' => $auth,
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [DossiersEndpoint::class, 'delete'],
                'permission_callback' => $auth,
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/dossiers/(?P<id>\d+)/renew', [
            'methods'             => 'POST',
            'callback'            => [DossiersEndpoint::class, 'renew'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/dossiers/(?P<id>\d+)/provision', [
            'methods'             => 'POST',
            'callback'            => [DossiersEndpoint::class, 'provision'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/dossiers/(?P<id>\d+)/migrate-host', [
            'methods'             => 'POST',
            'callback'            => [DossiersEndpoint::class, 'migrateHost'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/dossiers/(?P<id>\d+)/credentials/rotate', [
            'methods'             => 'POST',
            'callback'            => [DossiersEndpoint::class, 'rotateCredentials'],
            'permission_callback' => $auth,
        ]);

        // ─── Clients ───
        register_rest_route(self::NAMESPACE, '/clients', [
            'methods'             => 'GET',
            'callback'            => [ClientsEndpoint::class, 'list'],
            'permission_callback' => $auth,
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50],
                'search'   => ['type' => 'string',  'default' => ''],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/clients/(?P<id>\d+)/journey', [
            'methods'             => 'GET',
            'callback'            => [ClientsEndpoint::class, 'journey'],
            'permission_callback' => $auth,
        ]);

        // ─── Panels IPTV (agrégé) ───
        register_rest_route(self::NAMESPACE, '/panels', [
            'methods'             => 'GET',
            'callback'            => [PanelsEndpoint::class, 'list'],
            'permission_callback' => $auth,
        ]);

        // ─── Audit log ───
        register_rest_route(self::NAMESPACE, '/audit-log', [
            'methods'             => 'GET',
            'callback'            => [AuditLogEndpoint::class, 'list'],
            'permission_callback' => $auth,
            'args' => [
                'limit'       => ['type' => 'integer', 'default' => 100],
                'offset'      => ['type' => 'integer', 'default' => 0],
                'action'      => ['type' => 'string',  'default' => ''],
                'target_type' => ['type' => 'string',  'default' => ''],
            ],
        ]);

        // ─── Email templates ───
        register_rest_route(self::NAMESPACE, '/email-templates', [
            [
                'methods'             => 'GET',
                'callback'            => [EmailTemplatesEndpoint::class, 'list'],
                'permission_callback' => $auth,
            ],
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [EmailTemplatesEndpoint::class, 'save'],
                'permission_callback' => $auth,
            ],
        ]);

        // ─── URL Monitor (santé des hosts IPTV) ───
        register_rest_route(self::NAMESPACE, '/url-health', [
            'methods'             => 'GET',
            'callback'            => [UrlHealthEndpoint::class, 'list'],
            'permission_callback' => $auth,
        ]);
        register_rest_route(self::NAMESPACE, '/url-health/check', [
            'methods'             => 'POST',
            'callback'            => [UrlHealthEndpoint::class, 'triggerCheck'],
            'permission_callback' => $auth,
        ]);

        // ─── Cron renouvellement ───
        register_rest_route(self::NAMESPACE, '/cron/renewal', [
            [
                'methods'             => 'GET',
                'callback'            => [CronEndpoint::class, 'getRenewal'],
                'permission_callback' => $auth,
            ],
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [CronEndpoint::class, 'saveRenewal'],
                'permission_callback' => $auth,
            ],
        ]);
    }
}
