# Boutiques connectées — alimenter le catalogue depuis un autre site e-commerce

Permet d'importer les produits d'une boutique déjà en ligne (**Shopify**, **WooCommerce**,
**PrestaShop**) et de les garder à jour, ou de laisser **n'importe quelle plateforme** (ou un outil
comme Zapier / Make) envoyer ses produits via une API.

Administration → **Boutiques connectées** (`/admin/connectors.php`).

## Principe

- **Sens unique** : la boutique d'origine reste la référence. Le site la *lit*, ne la modifie jamais.
- Un produit est identifié par `(boutique, identifiant distant)` : une nouvelle synchro **met à jour** la fiche, sans doublon.
- Les nouvelles fiches sont créées **masquées** (réglable) pour être relues avant mise en ligne
  (Catalogue → filtre « Voir les produits » de la boutique).
- Les photos sont téléchargées (4 max par produit), redimensionnées et stockées dans `uploads/`.
- Les identifiants (clés API) sont stockés dans `.secrets/connector-<id>.json` (hors base, hors déploiement), jamais affichés après saisie.

## Ce qu'une synchro met à jour

| Champ | Création | Re-synchro |
|---|---|---|
| Prix, prix barré / promo | oui | **toujours** |
| Stock | oui | toujours (décochable : « le stock de la boutique d'origine écrase le mien ») |
| Poids (frais de port) | oui | toujours si renseigné |
| Nom, description | oui | non (cochable : « réécrire aussi le nom et la description ») |
| Photos | oui | seulement si la fiche n'en a aucune |
| Univers (catégorie) | deviné par mots-clés, sinon valeur par défaut de la boutique | non |
| Masquée / publiée, mise en avant, prix d'achat | — | **jamais touchés** |
| Produit disparu de la boutique (supprimé, brouillon, archivé) | — | passe en **rupture** (stock 0), jamais supprimé. Il revient en vente s'il réapparaît. |

Garde-fou : si la boutique ne renvoie *aucun* produit (erreur, API vidée), rien n'est retiré.

Promo : un prix barré supérieur au prix de vente devient badge « Promo » + prix promo.

## Connexion par plateforme

### Shopify
- Adresse : `ma-boutique.myshopify.com` (ou le domaine personnalisé).
- **Sans jeton** : lecture du catalogue public `/products.json` (prix, photos, « disponible » oui/non → stock 1 ou 0). Certaines boutiques le désactivent ou sont protégées par mot de passe.
- **Avec jeton** (recommandé, stocks exacts) : Admin Shopify → Paramètres → Applications → Développer des applications → créer une application, droits `read_products` + `read_inventory` → jeton `shpat_…`.
- Une **variante = une fiche** (« Plaid — Rouge »). Produits non actifs ignorés.

### WooCommerce
- WordPress → WooCommerce → Réglages → Avancé → API REST → Ajouter une clé, droit **Lecture** → `ck_…` / `cs_…`.
- Le site doit être en **https**. Si le domaine redirige (apex → `www`), saisissez l'adresse finale.
- Produits simples et **variations** importés (une variation = une fiche). Produits groupés / externes ignorés.
- Le poids est converti d'après l'unité réglée dans WooCommerce.

### PrestaShop
- Paramètres avancés → Webservice : activer, créer une clé avec lecture (GET) sur `products`, `images`, `stock_availables`.
- L'API donne les prix **hors taxes** : le champ « TVA à ajouter » (20 % par défaut) est appliqué.
- Les **déclinaisons** ne sont pas importées : le produit de base l'est, avec son stock global.
- Univers deviné d'après le nom du produit.

### Autre plateforme (API d'envoi)
Créer une source « Autre plateforme » : un **jeton** est affiché une seule fois (« Nouveau jeton » pour le remplacer, l'ancien est révoqué).

```bash
curl -X POST https://VOTRE-SITE/api/catalog-push.php \
  -H "Authorization: Bearer VOTRE_JETON" \
  -H "Content-Type: application/json" \
  -d '{"products":[{"external_id":"SKU-001","name":"Vase en grès","description":"Années 60.","price":"34,00","compare_at_price":"45,00","stock":1,"weight_grams":850,"category":"vase","images":["https://exemple.fr/photo.jpg"],"url":"https://exemple.fr/p/sku-001"}]}'
```

| Champ | Obligatoire | Notes |
|---|---|---|
| `external_id` | oui | Identifiant stable côté plateforme (≤ 190 car.). |
| `name` | oui | |
| `price` | oui | Nombre ou texte (`34`, `"34,00"`, `"34.00 €"`). |
| `compare_at_price` | non | Ancien prix, > `price` → promo. |
| `stock` | non | Absent = 1. |
| `active` | non | `false` → rupture de stock. |
| `weight_grams` | non | Sert aux frais de port. |
| `description` | non | HTML accepté (nettoyé en texte). |
| `category` | non | Mot-clé servant à deviner l'univers. |
| `images` | non | URLs http(s) publiques, 4 max. |
| `url` | non | Lien vers la fiche d'origine. |

Réponse : `200` + `{created, updated, failed, results:[{index, external_id, status, error?}]}` ;
`401` jeton invalide ; `413` > 25 produits ou > 2 Mo (envoyer par lots) ; `422` tout en échec ;
`400` HTTPS absent ou JSON invalide. Si l'hébergeur retire l'en-tête `Authorization`, utiliser `X-Api-Key: <jeton>`.

## Synchronisation

- **À la main** : bouton « Synchroniser ». Le navigateur traite la boutique par lots de 20 produits (pour tenir dans le délai du serveur) ; on peut relancer après une interruption.
- **Automatique** (cron OVH, par ex. chaque nuit) :
  ```bash
  php scripts/connectors-sync.php        # toutes les boutiques actives
  php scripts/connectors-sync.php 3      # uniquement la boutique n°3
  ```
  Code de sortie 1 si une synchro est incomplète ou a des échecs.

## Limites connues (à savoir avant de s'y fier)

1. **Stock à sens unique** : une vente faite *ici* décrémente le stock local, mais **pas** celui de la boutique d'origine ; et la synchro suivante réécrit le stock local avec celui d'origine (cela « annule » la vente locale). Pour un article vendu sur les deux sites, il y a donc un risque de double vente. Si le stock local doit primer, décocher « Le stock de la boutique d'origine écrase le mien » après l'import.
2. Prix : ils sont repris tels que l'API les donne (Shopify/WooCommerce : selon la configuration TTC/HT de la boutique ; PrestaShop : HT + TVA saisie). Vérifier un produit à la première synchro.
3. Un produit **supprimé du catalogue** ici réapparaît à la synchro suivante (nouvelle réf.). Pour l'écarter définitivement, le **masquer** au lieu de le supprimer.
4. Modifier le prix ou le stock d'un produit importé dans le catalogue est écrasé à la synchro suivante.
5. Pas de déclinaisons PrestaShop, pas de produits groupés/abonnements WooCommerce.

## Sécurité

- Les adresses saisies et celles des images ne sont suivies que si elles sont en **https**, sur les ports 80/443, et **résolvent vers une adresse publique** (réseaux privés, localhost, métadonnées cloud refusés ; l'IP vérifiée est épinglée pour contrer le DNS rebinding). Redirections revalidées (3 max) ; les identifiants ne suivent jamais une redirection vers un autre domaine.
- Réponses et images plafonnées en taille ; les images sont réencodées (aucun fichier distant n'est servi tel quel).
- Actions admin protégées par le mot de passe admin + jeton CSRF ; l'API d'envoi n'utilise aucun cookie, seul le jeton (dont seule l'empreinte SHA-256 est conservée) authentifie.

## Tests

```bash
php scripts/test-connectors.php
```
Lance un faux Shopify / WooCommerce / PrestaShop local (`scripts/fixtures/connectors-mock.php`) et teste imports, mises à jour, retraits, garde-fous réseau et API d'envoi, sur une base et des dossiers jetables.

## Fichiers

- `includes/connectors.php` : toute la logique (schéma, réseau sûr, adaptateurs, import, synchro).
- `admin/connectors.php`, `admin/connectors-action.php` : interface et actions.
- `api/catalog-push.php` : API d'envoi. `scripts/connectors-sync.php` : synchro en ligne de commande.
