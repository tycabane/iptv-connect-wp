<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Api\Endpoints;

use IptvConnect\Support\IptvCoreBridge;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * AuditLogEndpoint — GET /audit-log
 *
 * Lit la table d'audit log d'iptv-core (wp_iptv_audit_log).
 * Fallback direct sur le wpdb si AuditLogger::recent() n'est pas exposé.
 */
final class AuditLogEndpoint
{
    public static function list(WP_REST_Request $request)
    {
        if (($err = IptvCoreBridge::requireOrError()) !== true) return $err;

        $limit  = max(1, min(500, (int) ($request->get_param('limit') ?: 100)));
        $offset = max(0, (int) $request->get_param('offset'));
        $action = sanitize_text_field((string) $request->get_param('action'));
        $target = sanitize_text_field((string) $request->get_param('target_type'));

        // Méthode 1 : passer par la classe AuditLogger si dispo
        $class = '\\IptvCore\\Security\\AuditLogger';
        if (class_exists($class) && method_exists($class, 'recent') && $offset === 0 && $action === '' && $target === '') {
            try {
                $items = call_user_func([$class, 'recent'], $limit);
                return new WP_REST_Response([
                    'source' => 'AuditLogger::recent',
                    'items'  => is_array($items) ? $items : [],
                    'total'  => is_array($items) ? count($items) : 0,
                ], 200);
            } catch (\Throwable $e) {
                // continue avec fallback wpdb
            }
        }

        // Méthode 2 : SQL direct sur la table
        global $wpdb;
        $table = $wpdb->prefix . 'iptv_audit_log';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return new WP_Error('iptv_connect_no_audit_table', 'Table audit absente.', ['status' => 503]);
        }

        $where = [];
        $args  = [];
        if ($action !== '') { $where[] = 'action = %s';      $args[] = $action; }
        if ($target !== '') { $where[] = 'target_type = %s'; $args[] = $target; }
        $sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_sql = "SELECT COUNT(*) FROM {$table} {$sql_where}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, ...$args)) : $wpdb->get_var($count_sql));

        $list_sql = "SELECT id, user_id, action, target_type, target_id, ip, user_agent, context, created_at
                     FROM {$table} {$sql_where}
                     ORDER BY id DESC LIMIT %d OFFSET %d";
        $list_args = array_merge($args, [$limit, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_args), ARRAY_A);

        // Décode le JSON context si possible
        foreach ($rows as &$row) {
            if (!empty($row['context']) && is_string($row['context'])) {
                $decoded = json_decode($row['context'], true);
                if (is_array($decoded)) $row['context'] = $decoded;
            }
        }
        unset($row);

        return new WP_REST_Response([
            'source' => 'wpdb',
            'items'  => $rows ?: [],
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ], 200);
    }
}
