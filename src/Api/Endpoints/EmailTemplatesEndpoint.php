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
 * EmailTemplatesEndpoint
 *
 * GET /email-templates      · liste tous les templates (subject + body + body_html)
 * PUT /email-templates      · sauvegarde un ou plusieurs templates
 *
 * Délègue à \IptvCore\Email\TemplateEngine si présent, sinon lit l'option `iptv_cockpit_templates`.
 */
final class EmailTemplatesEndpoint
{
    private const OPT_KEY = 'iptv_cockpit_templates';

    public static function list(WP_REST_Request $request)
    {
        $class = '\\IptvCore\\Email\\TemplateEngine';
        if (class_exists($class) && method_exists($class, 'getAll')) {
            try {
                $templates = call_user_func([$class, 'getAll']);
                return new WP_REST_Response(['templates' => $templates, 'source' => 'TemplateEngine'], 200);
            } catch (\Throwable $e) {
                // fallback option
            }
        }

        $stored = get_option(self::OPT_KEY, []);
        if (!is_array($stored)) $stored = [];
        return new WP_REST_Response(['templates' => $stored, 'source' => 'option'], 200);
    }

    /**
     * Body JSON : { templates: { template_key: { subject, body, body_html } } }
     */
    public static function save(WP_REST_Request $request)
    {
        $body = (array) $request->get_json_params();
        $templates = $body['templates'] ?? null;
        if (!is_array($templates) || empty($templates)) {
            return new WP_Error('iptv_connect_bad_body', 'Body doit contenir { templates: {...} }', ['status' => 400]);
        }

        // Sanitize
        $clean = [];
        foreach ($templates as $key => $tpl) {
            if (!is_string($key) || !is_array($tpl)) continue;
            $clean[sanitize_key($key)] = [
                'subject'   => sanitize_text_field((string) ($tpl['subject']   ?? '')),
                'body'      => (string) ($tpl['body']      ?? ''), // contenu texte/html : conservé tel quel
                'body_html' => (string) ($tpl['body_html'] ?? ''),
            ];
        }

        $class = '\\IptvCore\\Email\\TemplateEngine';
        if (class_exists($class) && method_exists($class, 'saveAll')) {
            try {
                $ok = call_user_func([$class, 'saveAll'], $clean);
                IptvCoreBridge::audit('EDIT_EMAIL_TEMPLATES', 'option', 0, ['keys' => array_keys($clean)]);
                return new WP_REST_Response(['ok' => (bool) $ok, 'source' => 'TemplateEngine'], 200);
            } catch (\Throwable $e) {
                return new WP_Error('iptv_connect_save_exception', $e->getMessage(), ['status' => 500]);
            }
        }

        // Fallback : merge dans l'option
        $current = get_option(self::OPT_KEY, []);
        if (!is_array($current)) $current = [];
        $merged  = array_replace_recursive($current, $clean);
        update_option(self::OPT_KEY, $merged);
        IptvCoreBridge::audit('EDIT_EMAIL_TEMPLATES', 'option', 0, ['keys' => array_keys($clean)]);
        return new WP_REST_Response(['ok' => true, 'source' => 'option', 'updated_keys' => array_keys($clean)], 200);
    }
}
