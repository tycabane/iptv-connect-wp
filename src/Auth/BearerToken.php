<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Auth;

use IptvConnect\Bootstrap;
use WP_REST_Request;
use WP_Error;

/**
 * BearerToken — vérifie la clé API envoyée dans le header `Authorization: Bearer X`
 * ou dans le query string `?api_key=X` (fallback pour debug curl).
 *
 * Utilisé comme callback `permission_callback` sur chaque endpoint REST.
 */
final class BearerToken
{
    /**
     * Permission callback REST WordPress.
     * Retourne true si la clé est valide, sinon WP_Error 401.
     *
     * @return true|WP_Error
     */
    public static function check(WP_REST_Request $request)
    {
        $expected = Bootstrap::getApiKey();
        if ($expected === '') {
            return new WP_Error('iptv_connect_no_key', 'Clé API non configurée côté site.', ['status' => 503]);
        }

        $provided = self::extractToken($request);
        if ($provided === '') {
            return new WP_Error('iptv_connect_missing_token', 'Token manquant. Envoyer header "Authorization: Bearer X" ou ?api_key=X', ['status' => 401]);
        }

        // Comparaison temps-constant pour éviter timing attacks
        if (!hash_equals($expected, $provided)) {
            return new WP_Error('iptv_connect_invalid_token', 'Token invalide.', ['status' => 403]);
        }

        return true;
    }

    private static function extractToken(WP_REST_Request $request): string
    {
        // 1. Header Authorization (méthode standard)
        $auth = (string) $request->get_header('authorization');
        if ($auth === '') {
            // Fallback : certains hébergeurs strippent le header → essayer X-Api-Key
            $auth = (string) $request->get_header('x_api_key');
            if ($auth !== '') return trim($auth);
        }
        if (str_starts_with(strtolower($auth), 'bearer ')) {
            return trim(substr($auth, 7));
        }

        // 2. Query string ?api_key=X (fallback pour debug curl rapide)
        $qp = (string) $request->get_param('api_key');
        if ($qp !== '') return trim($qp);

        return '';
    }
}
