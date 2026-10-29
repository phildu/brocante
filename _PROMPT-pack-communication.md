# Prompt — Pack « Communication / Promotion » (session indépendante)

> À coller tel quel au début d'une nouvelle session Claude Code ouverte dans `/Users/admin/Documents/projet/web local/brocante`
> (la session doit aussi pouvoir lire `/Users/admin/Documents/projet/web local/electroboy80`, projet source).

---

## Mission

Tu travailles pour moi (français, toujours) sur ma plateforme de boutiques en marque blanche (PHP 8 + SQLite, multi-commerces). Je veux **extraire d'electroboy80 le pack « Communication / Promotion »**
— **éditeur visuel**, **médiathèque**, **publication sur les réseaux sociaux (RS)** — et **le propager sur mes autres sites** (les boutiques générées par la plateforme, et au besoin mes autres projets PHP),
sous forme d'un **plugin installable, activable par boutique, mis à jour depuis une source unique**.
Il faut donc aussi **adapter le portail et les boutiques générées pour recevoir des plugins** (activation, mise à jour, droits, clés, offres).

Nous avons déjà analysé le projet et commencé un prototype ; **reprends ce travail, ne repars pas de zéro** (voir « État des lieux »). Découpe en étapes, **livre et fais valider chaque étape** avant la suivante.

## Contexte de la plateforme (à relire avant d'agir)

- Racine : `/Users/admin/Documents/projet/web local/brocante` — lis `README.md` (long, à jour : multi-commerces, Studio, galeries, offres IA…), `config.php`, `includes/tenant.php`, `includes/functions.php`.
- **Multi-commerces** : un commerce = `tenants/<slug>/tenant.php` (+ `seed-data.json`), base `data/<slug>.db`, secrets `.secrets/<slug>/` (jamais déployés), assets `assets/tenants/<slug>/`. Résolution par `tenant_slug()`, `tenant_base_path()`, `app_prefix()`.
  Test local d'un commerce : `TENANT=la-fripe-a-naty php -S 127.0.0.1:8091 router.php` (portail/galeries : même commande **sans** `TENANT`, autre port).
- **Déploiements** (OVH, FTP) : `./deploy-brocante.sh` (boutique Petit Chalet, https://brocante.arrimage.com) et `./deploy-brocante.sh --portail` (portail + **toutes** les boutiques + galeries, https://brocs.arrimage.com).
  Le script fait un `git add -A` + commit « deploy-preprod … » : après coup, on remplace ce commit par un commit du **seul code** (jamais `uploads/`, `tenants/kllklmk`, `assets/tenants/kllklmk`).
- **Portail** (`portail/`) : gestion des boutiques, clés partagées (`portail/cles.php`), connexion sociale (`portail/social.php`, `includes/oauth.php`), offres/tarifs (`portail/offres.php`, `data/saas/tarifs.json`, `includes/ai-plans.php` pour le quota IA),
  événements (`includes/saas-events.php`), inscription/création de boutique (`includes/saas.php`, `includes/tenant-factory.php`).
- **Administration d'une boutique** : `admin/*.php` (menu dans `includes/admin-nav.php`, rôles dans `includes/accounts.php`), médiathèque existante `admin/media.php`, galerie produit `admin/gallery.php`, Studio mobile `studio.php`.
- Pièges connus : `config.php` → `db()` charge paresseusement plusieurs `includes/*.php` (promo, pricing-extras, recto-verso, ambiance-scenes…) — un script qui appelle ces fonctions avant `db()` doit les `require_once` ; macOS : `sed -i` demande un suffixe, zsh refuse les globs sans correspondance ;
  l'hébergement OVH est **PHP seul (pas de Node)**, `mail()` fourni par l'hébergeur.

## Source à extraire : electroboy80

Dossier : `/Users/admin/Documents/projet/web local/electroboy80` (README, `DOC-*.md`, `api/`, `js/`, `plugin/`, `deploy*.sh`). Fonctionnalités du pack, à inventorier précisément (fichiers, tables, routes, dépendances, secrets) :

1. **Éditeur visuel** (retouche/effets d'image et de vidéo, états sauvegardés) — `js/eb80-effects.js`, `plugin/editeur.js|css`, états dans `eb80_media_states`.
2. **Médiathèque** (import, titres/crédits/alt/tags, galerie, versions web) — `api/media.php`, `api/media-links.php`, `api/generate-web-images.php`, `js/media-picker-outils.js`, `js/media-validation.js`, `plugin/mediatheque.js|css`.
3. **Publication RS** (Facebook, Instagram, TikTok…, planification, historique, feed) — `api/rs-publish.php`, `api/rs-schedule.php`, `api/rs-v2.php`, `api/rs-tiktok-callback.php`, `api/instagram.php`, `api/social-feed.php`, `api/pub.php`, `plugin/social-feed.js|css`, `DOC-VIDEO-PROMO.md` (vidéo promo).
4. **Ce qui sert de ciment** : `api/auth.php`, `api/config.php`, `api/settings.php`, `api/ai-generate.php` (appels IA), `api/notify.php`.

## État des lieux (déjà fait, non commité — « WIP plugin »)

Ces éléments existent dans l'arbre de travail mais sont **volontairement exclus des commits/déploiements** (le déploiement les met de côté puis les remet à l'identique) :

- `plugin/` : prototype v1.0.0 (`version.json`, `update.php` = mise à jour par comparaison de versions depuis une source distante, `schema.sql` tables `eb80_*`, `api/config.php` + `media.php` + `social.php`, `mediatheque.js|css`, `social-feed.js|css`, `editeur.js|css`, `demo.html`).
  Limites connues : préfixe `eb80_` en dur, authentification de l'API propre au plugin (mot de passe admin réutilisé / jeton) au lieu de la session de la boutique, dualité MySQL/SQLite, secret de mise à jour optionnel, pas de gestion multi-commerces ni de quotas.
- `admin/plugin-mediatheque.php` (page d'essai à onglets), entrée de menu `'plugin-media'` dans `includes/admin-nav.php`.
- `.gitignore` (+ `plugin/api/config.local.php`), `LocalValetDriver.php` (routes `/plugin/api/(media|auth|social)`).
- Portail : `portail/action.php` (actions `plugin_enable` / `plugin_disable` = fichier drapeau `tenants/<slug>/.plugin_enabled`), bouton « Activer plugin médiathèque » dans `portail/index.php`.
- `tenants/petit-chalet/.plugin_enabled`.
- Les analyses antérieures sont dans les transcripts des sessions précédentes : `/Users/admin/.claude/projects/-Users-admin-Documents-projet-web-local-brocante/*.jsonl` (cherche « plugin », « eb80 », « médiathèque »). Relis-les avant de réanalyser.

⚠️ Avant de modifier `includes/admin-nav.php` ou `portail/index.php` : ils mélangent mes autres évolutions et les lignes du plugin ; fais `git diff` d'abord et ne casse pas le reste. Les fichiers du plugin ne doivent pas partir en production tant que je n'ai pas validé la livraison (voir « Règles »).

## Cible : architecture d'un plugin pour la plateforme

À concevoir (propose, discute, puis réalise) — objectifs :

1. **Un seul code source du pack** (idéalement versionné dans un dossier dédié de ce dépôt, ex. `packs/communication/`), **déployé une fois** et **servi à toutes les boutiques** (pas une copie par commerce), avec **données isolées par commerce** (tables dans `data/<slug>.db`, médias sous `uploads/` ou un dossier par boutique, secrets dans `.secrets/<slug>/`).
2. **Contrat de plugin** : un manifeste (`plugin.json` : id, nom, version, dépendances, tables/migrations, routes, entrées de menu admin, permissions/rôles, réglages, hooks front éventuels) lu par la plateforme. Le prototype `version.json` + `update.php` en est l'embryon.
3. **Côté boutique générée** : chargeur de plugins (liste des plugins activés pour le commerce, migrations idempotentes à l'activation comme les `ALTER TABLE` de `config.php`, routes `/plugin/...` ou `/admin/plugin-...` gérées par `router.php`, `LocalValetDriver.php` et les règles du portail), entrées de menu dans `includes/admin-nav.php` **par rôle** (`includes/accounts.php` : un community manager n'a que les pages listées), authentification **par la session admin existante** (plus de mot de passe dans l'API), CSRF, pas de fuite entre commerces.
4. **Côté portail** : page « Plugins » (catalogue, version installée par boutique, activer/désactiver, mettre à jour tous les commerces d'un clic, journal dans `saas-events`), **clés RS centralisées** (Meta/TikTok… dans `.secrets/…` plateforme, avec surcharge par boutique, sur le modèle de `includes/oauth.php` et `includes/shared-secrets.php`), et **rattachement aux offres** (le pack comme option d'une formule dans `data/saas/tarifs.json`, avec le quota IA/crédit déjà géré par `includes/ai-plans.php`).
5. **Création de boutique** (`includes/tenant-factory.php`, `includes/saas.php`) : le pack s'active à la création selon la formule choisie.
6. **Mises à jour** : source unique, comparaison de versions, migration des tables, journal, retour arrière simple ; compatible avec `deploy-brocante.sh --portail` (qui envoie le code ; les bases et `.secrets/` ne sont jamais écrasées).
7. **Réutilisable hors plateforme** (mes autres sites PHP) : le plugin doit pouvoir s'installer sur un site « nu » avec un fichier de configuration minimal.

## Étapes proposées (valide chacune avec moi avant la suivante)

0. **Audit et plan** (aucun code) : inventaire exact d'electroboy80 (fichiers, tables, endpoints, secrets, dépendances externes, points couplés à electroboy80 : chemins, base MySQL/SQLite, auth, IA), écart avec le prototype `plugin/`, risques, découpage détaillé, estimation. Livre un document court + les questions ouvertes.
1. **Socle plugin** : manifeste, chargeur côté boutique (activation par commerce, migrations, routes, menu par rôle, auth par session), page « Plugins » du portail (activer/désactiver/version/mise à jour), mise à jour depuis la source. Sans aucune fonctionnalité métier encore : un plugin « hello » de démonstration suffit à valider le socle.
2. **Médiathèque** (la plus simple et la plus utile) : port complet, isolation par commerce, intégration avec la médiathèque existante `admin/media.php` et les photos produit (choisir une image de la médiathèque depuis la fiche, le diaporama, les bandeaux), versions web, tests.
3. **Éditeur visuel** : port de l'éditeur (effets image/vidéo, états), ouverture depuis la médiathèque et depuis la galerie produit ; coût IA éventuel rattaché au quota (`ai_usage_context`).
4. **Publication RS** : comptes/clés par boutique (OAuth, jetons, renouvellement), publication immédiate et planifiée (tâche planifiée compatible OVH : cron/endpoint protégé), historique, aperçu, feed ; gestion TikTok/Instagram/Facebook selon ce qui existe dans electroboy80 ; respect des limites des API.
5. **Intégration offres + création de boutique + monétisation** (option payante, quotas, événements), documentation, tests de non-régression sur les boutiques existantes.
6. **Propagation** : activation sur Petit Chalet puis les autres boutiques, mise à jour de masse, vérification en ligne.

## Règles de travail (non négociables)

- **Réponds et documente en français.** Lis le code avant de le modifier ; respecte le style existant (commentaires en français, nommage, une idée par fichier) et **mets le `README.md` à jour** à chaque étape.
- **Ne déploie jamais et ne commite jamais sans mon « go » explicite** (« déployer » / « go »). Quand je le donne : tout le code vaut commit, **jamais** `uploads/`, `tenants/kllklmk`, `assets/tenants/kllklmk` ; message de commit en français avec la ligne de co-signature habituelle. Quand tu déploies, tiens compte du comportement du script (snapshot `git add -A`).
- **Teste en local avant de proposer** : serveur PHP intégré, vraies requêtes, navigateur intégré (bureau et mobile), session admin factice, **puis nettoie toutes les données de test** (comptes, produits, fichiers, réglages) ; ne laisse pas de secrets en clair dans le dépôt ni dans les réponses.
- **Aucune surprise destructive** : pas d'écrasement de base de production, pas de suppression de données ; les boutiques existantes ne doivent rien perdre ni changer de comportement tant qu'elles n'ont pas le plugin.
- **Sécurité** : isolation stricte entre commerces, droits par rôle, CSRF sur toute action, secrets hors du dépôt (`.secrets/`), pas de mot de passe dans les URL ni les jetons côté navigateur, uploads validés (type, taille, noms).
- Les actions sortantes (publier sur un vrai réseau social, envoyer un e-mail, appeler une API payante) se testent avec des **simulateurs ou des comptes de test**, et tu me demandes avant tout essai réel.
- En cas de doute d'architecture structurant (nom, emplacement, modèle de droits, tarification), **pose-moi la question** avec une recommandation plutôt que de choisir seul.

## Première action attendue

Étape 0 uniquement : lis ce prompt, les transcripts utiles, le prototype `plugin/` et le code d'electroboy80, puis rends-moi **l'audit, le plan détaillé par étapes avec critères de validation, et tes questions ouvertes**. N'écris pas de code avant mon accord sur le plan.
