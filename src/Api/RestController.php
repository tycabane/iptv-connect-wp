<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Api;

use IptvConnect\Api\Endpoints\ClientsEndpoint;
use IptvConnect\Api\Endpoints\DossiersEndpoint;
use IptvConnect\Api\Endpoints\HealthEndpoint;
use IptvConnect\Api\Endpoints\KpisEndpoint;
use IptvConnect\Auth\BearerToken;

/**
 * RestController — enregistre les routes /wp-json/iptv-connect/v1/*
 *
 * Toutes les routes utilisent BearerToken::check comme permission_callback,
 * sauf /health qui est publique (mais retourne quand même un minimum d'info,
 * pas de data sensible).
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
        // GET /health — publique (utile pour ping uptime)
        register_rest_route(self::NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => [HealthEndpoint::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);

        // GET /dossiers — liste paginée
        register_rest_route(self::NAMESPACE, '/dossiers', [
            'methods'             => 'GET',
            'callback'            => [DossiersEndpoint::class, 'list'],
            'permission_callback' => [BearerToken::class, 'check'],
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50],
                'search'   => ['type' => 'string',  'default' => ''],
                'status'   => ['type' => 'string',  'default' => ''],
            ],
        ]);

        // GET /dossiers/{id} — détail (sans credentials par défaut)
        register_rest_route(self::NAMESPACE, '/dossiers/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [DossiersEndpoint::class, 'get'],
            'permission_callback' => [BearerToken::class, 'check'],
            'args' => [
                'include_credentials' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        // GET /clients — liste clients agrégés
        register_rest_route(self::NAMESPACE, '/clients', [
            'methods'             => 'GET',
            'callback'            => [ClientsEndpoint::class, 'list'],
            'permission_callback' => [BearerToken::class, 'check'],
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50],
                'search'   => ['type' => 'string',  'default' => ''],
            ],
        ]);

        // GET /kpis — métriques business
        register_rest_route(self::NAMESPACE, '/kpis', [
            'methods'             => 'GET',
            'callback'            => [KpisEndpoint::class, 'handle'],
            'permission_callback' => [BearerToken::class, 'check'],
        ]);
    }
}
