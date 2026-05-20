<?php
/**
 * @package IptvConnect
 */

declare(strict_types=1);

namespace IptvConnect\Support;

use WP_Error;

/**
 * IptvCoreBridge — point d'accès unique aux classes d'iptv-core.
 *
 * Toutes les méthodes retournent WP_Error si iptv-core n'est pas installé / activé.
 * Permet aux endpoints d'écriture de fail fast et proprement sur les sites WC-only.
 */
final class IptvCoreBridge
{
    public static function isAvailable(): bool
    {
        return post_type_exists('iptv_dossier')
            && class_exists('\\IptvCore\\Security\\CredsVault');
    }

    /** @return WP_Error|true */
    public static function requireOrError()
    {
        return self::isAvailable() ? true : new WP_Error(
            'iptv_connect_core_missing',
            'Cette action requiert le plugin iptv-core, non détecté sur ce site.',
            ['status' => 503]
        );
    }

    /**
     * Instancie DossierCreds (avec CredsVault injecté).
     * Retourne null si iptv-core absent OU si IPTV_MASTER_KEY manquant.
     */
    public static function creds(): ?object
    {
        if (!self::isAvailable()) return null;
        try {
            $vault = new \IptvCore\Security\CredsVault();
            return new \IptvCore\Security\DossierCreds($vault);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Logge une action dans la table audit (si iptv-core dispo).
     * Le user_id sera 0 (action API externe) — l'IP du dashboard sera capturée.
     */
    public static function audit(string $action, string $target_type, int $target_id, array $context = []): void
    {
        if (!class_exists('\\IptvCore\\Security\\AuditLogger')) return;
        try {
            \IptvCore\Security\AuditLogger::log($action, $target_type, $target_id, array_merge($context, ['via' => 'iptv-connect']));
        } catch (\Throwable $e) {
            // silencieux : audit best-effort
        }
    }
}
