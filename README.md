# IPTV Connect

Plugin WordPress léger qui expose les données d'un site IPTV (dossiers, clients, KPIs) vers un dashboard admin externe multi-site, via une API REST sécurisée par token Bearer.

## Installation rapide

1. Télécharge la dernière `iptv-connect.zip` depuis [Releases](https://github.com/tycabane/iptv-connect-wp/releases/latest)
2. WordPress → Extensions → Ajouter → Téléverser une extension → choisir le `.zip`
3. Activer
4. Aller dans **Réglages → 🔌 Dashboard Connect** : la clé API est générée automatiquement
5. Copier cette clé dans le dashboard externe

## Compatibilité

| Composant | Requis ? | Comportement |
|-----------|----------|--------------|
| WordPress ≥ 6.0 | ✅ | obligatoire |
| PHP ≥ 8.0 | ✅ | obligatoire |
| WooCommerce | ⚠️ | optionnel — utilisé comme fallback si pas d'`iptv-core` |
| iptv-core (CPT `iptv_dossier`) | ⚠️ | optionnel — source prioritaire si présent |

Le plugin fonctionne même sur un site WP "nu" (sans WC ni iptv-core), il retournera juste des collections vides avec une note explicative.

## Endpoints REST

Base : `https://<site>/wp-json/iptv-connect/v1/`

| Méthode | Route | Auth | Description |
|---------|-------|------|-------------|
| GET | `/health` | ❌ publique | ping + versions + stack info |
| GET | `/dossiers?page=1&per_page=50&search=&status=` | Bearer | liste paginée des dossiers |
| GET | `/dossiers/{id}?include_credentials=false` | Bearer | détail d'un dossier (credentials chiffrés sur demande) |
| GET | `/clients?page=1&per_page=50&search=` | Bearer | utilisateurs WP + agrégats dossiers/commandes |
| GET | `/kpis` | Bearer | métriques business (MRR, actifs, expirent, new) |

### Authentification

Header recommandé :
```http
Authorization: Bearer XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```

Fallback (debug curl) : `?api_key=XXX...` dans la query string.

### Exemple

```bash
curl -H "Authorization: Bearer $KEY" \
  "https://mon-site.com/wp-json/iptv-connect/v1/kpis"
```

## Mise à jour automatique

Le plugin s'auto-met-à-jour depuis GitHub Releases. Quand vous publiez un nouveau tag `vX.Y.Z`, le workflow `.github/workflows/release.yml` :
1. Construit `iptv-connect.zip`
2. Crée la release sur GitHub
3. Les sites détectent la nouvelle version sous 6 h (cache transient WP) et proposent la MAJ depuis l'écran Extensions

### Si le repo est privé

Ajouter dans le `wp-config.php` de chaque site :
```php
define('IPTV_CONNECT_GH_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxx');
```
(token GitHub fine-grained avec `Contents: read` sur le repo `tycabane/iptv-connect-wp`)

## Sécurité

- Clé API : 48 chars hex (192 bits d'entropie), générée par `random_bytes()`
- Comparaison du token via `hash_equals()` (timing-safe)
- Credentials IPTV ne sont jamais retournés en clair sauf si `include_credentials=true` est passé explicitement sur un endpoint de détail
- Aucune écriture côté plugin : tous les endpoints sont en lecture seule pour la v0.1
- Page admin protégée par capability `manage_options`

## Développement

```bash
# clone
git clone git@github.com:tycabane/iptv-connect-wp.git
cd iptv-connect-wp

# créer un nouveau release
# 1. bump version dans iptv-connect.php + src/Bootstrap.php si nécessaire
# 2. tag + push
git tag v0.2.0
git push origin v0.2.0
# → GitHub Actions construit et publie le .zip automatiquement
```

## Roadmap

- **v0.1** ✅ — REST endpoints lecture (dossiers / clients / kpis / health) + auth Bearer + auto-update GH Releases
- **v0.2** — webhooks sortants signés HMAC (création dossier, expiration imminente)
- **v0.3** — endpoints actions distantes (renouveler dossier, rotate credentials)
- **v0.4** — push de logs structurés vers le dashboard

## Licence

Propriétaire — © AZ Services
