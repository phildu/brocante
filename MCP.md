# Serveur MCP — piloter les données du site depuis Claude

Permet à un assistant (Claude Code…) de lire et modifier les données d'un commerce via `https://<site>/mcp.php`
(protocole MCP, JSON-RPC sur HTTP POST). **Chaque commerce a son propre jeton** (la base du commerce est celle de l'adresse).

## Mise en route

1. **Déployer** le code (préprod puis prod, voir `DEPLOIEMENT.md`) : jamais automatique.
2. Sur le site visé : Administration → Réglages → **Accès MCP** → « Créer un jeton » (affiché une seule fois ; seule son
   empreinte SHA-256 est conservée dans `settings`). Le jeton est propre à l'environnement : celui de la préprod ne marche pas en prod.
3. Sur votre Mac :
   ```bash
   claude mcp add --transport http brocante-prod https://www.bricbrok.com/mcp.php --header "Authorization: Bearer bbmcp_…"
   ```
   (utiliser un nom différent par environnement : `brocante-preprod`, `brocante-local`). Pour un commerce servi sous le domaine d'un autre, l'adresse porte son identifiant : `https://pre.bricbrok.com/<identifiant>/mcp.php` ; la page Accès MCP affiche la bonne.
4. « Révoquer » ou « Nouveau jeton » coupe immédiatement l'ancien accès.

## Connexion sans jeton (OAuth) : Claude → Paramètres → Connecteurs

Le serveur gère OAuth 2.1 (enregistrement dynamique, PKCE S256) : dans Claude, Paramètres → Connecteurs → Ajouter → *Ajouter un connecteur
personnalisé*, adresse `https://<site>/<identifiant>/mcp.php` (celle que la page Accès MCP affiche). Claude ouvre `mcp-authorize.php` : le propriétaire
se connecte à l'administration du commerce (rôle admin) et clique « Autoriser ». Jetons d'accès : 1 h ; renouvellement : 30 jours, renouvelé à chaque
usage ; seules leurs empreintes SHA-256 sont conservées. « Révoquer » (Accès MCP) coupe le jeton statique **et** toutes les applications autorisées.
Fichiers : `mcp-oauth-meta.php` (découverte, aussi servie sous `/.well-known/…` par le `.htaccess`), `mcp-register.php`, `mcp-authorize.php`,
`mcp-token.php`, `includes/mcp-oauth.php`. Tables : `mcp_oauth_clients`, `mcp_oauth_codes`, `mcp_oauth_tokens`. Les règles `/.well-known` sont propres à Apache (OVH) ;
en local, `/mcp-oauth-meta.php?doc=resource|server` donne les mêmes documents.

## Outils

| Outil | Rôle |
|---|---|
| `site_overview` | environnement, univers valides, compteurs produits / commandes / remises |
| `products_list`, `product_get` | chercher / lire des produits |
| `product_update` | modifier des champs (liste blanche), `dry_run` pour prévisualiser |
| `product_create` | créer un produit, **masqué par défaut** |
| `content_get`, `content_update` | textes du site (accueil, histoire, contact, livraison, emballage cadeau) |
| `setting_set` | réglages autorisés : `footer_note`, `opening_hours`, `opening_notice`, `legal_address` |
| `discounts_list`, `discount_add`, `discount_set_active` | remises du panier (on désactive, on ne supprime pas) |
| `orders_list`, `order_get` | commandes, **lecture seule** |
| `audit_log` | journal des écritures avec valeurs avant / après (pour annuler un changement) |

Champs produit modifiables : `name, description, price, promo_price, badge, stock, is_hidden, featured, cat, materials, colors,
brand, etat, size_text, weight_text, weight_grams, lot_qty, negotiable`. Mêmes règles que le formulaire du catalogue
(promo < prix, 3 produits mis en avant maximum, univers et états validés). Photos, prix d'achat et clés de paiement ne sont pas exposés.

## Garde-fous

- **Aucune suppression** : ni produit, ni commande, ni remise. On masque (`is_hidden`) ou on désactive.
- **Sauvegarde avant écriture** : `data/backups/<base>-mcp-AAAAMMJJ-HHMMSS.db` (au plus une toutes les 10 min, 30 gardées).
  Si la sauvegarde échoue, l'écriture est refusée. Restaurer : remplacer la base par la sauvegarde, site au repos.
- **Journal** : table `mcp_audit` (180 jours), visible dans Accès MCP et via `audit_log`.
- **Authentification** : HTTPS obligatoire (sauf en local), en-tête `Origin` refusé (pas d'appel depuis une page web),
  blocage 10 minutes après 10 jetons faux depuis la même IP, réponse identique si jeton absent / faux / révoqué.
- **Produits importés** d'une boutique connectée : prix et stock sont réécrits à la prochaine synchro (voir `CONNECTEURS.md`).

## Limites

- Réponses JSON simples (pas de flux SSE, pas de session MCP) ; compatible Claude Code (`--transport http`, jeton) et connecteurs personnalisés (OAuth).
- Le jeton donne **tous** les droits ci-dessus : le traiter comme un mot de passe admin.
- Les réglages `opening_hours` est un JSON de l'administration : n'y toucher que sur demande explicite.

## Tests

```bash
vendor/bin/phpunit tests/McpTest.php
```

## Fichiers

`mcp.php` (entrée HTTP) · `includes/mcp.php` (outils, jeton, journal, sauvegarde, JSON-RPC) · `admin/mcp.php` (jeton + journal).
