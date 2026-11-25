# Brocante / Brocs / bricbrok — état du développement

> Document de reprise pour une autre IA ou un autre développeur.
> Rédigé le 2026-11-16, **mis à jour le 2026-11-22** à partir du dépôt local `brocante` (branche `claude/changes`, base `b22643c`, en retard de 50 commits sur `main` = `9f8006c`).
> À lire avec : `CLAUDE.md` (règles de session), `DEPLOIEMENT.md` (circuit de mise en ligne), `README.md` (très complet, ~1 600 lignes), `docs/SUIVI_TACHES.md` (backlog).

---

## 1. Ce que c'est

Plateforme **PHP + SQLite, sans framework ni serveur de base de données**, qui sert :

- des **boutiques en marque blanche** (« commerces », `tenants/<slug>/`) : catalogue, panier, paiement, administration, Studio photo IA ;
- des **galeries commerciales** (galerie marchande, marché aux puces, vide-grenier, quartier marchand, marché de Noël, marché des producteurs) : plan d'implantation, exposants, réservations, buvette, invendus & dons, comptage de foule, annulation/remboursement, sponsors, messagerie ;
- un **portail** (`portail/`) d'administration de la plateforme : boutiques, galeries, tarifs, commissions Stripe Connect, IA, retours testeurs, tâches, équipe, prospection, domaines OVH ;
- une **vitrine publique** (accueil, annuaire, inscription, centre d'aide `/aide/`).

Noms : dépôt GitHub `phildu/brocante` ; marque en ligne **bricbrok** ; anciens noms « Brocs », « brocenstock » (domaine Herd local).
Le Petit Chalet (`tenants/petit-chalet`) est le commerce historique ; les commerces d'essai locaux (`petit-chalet`, `la-fripe-a-naty`…) ne sont **jamais** déployés par le script.

## 2. Pile technique

| Élément | Détail |
|---|---|
| Langage | PHP 8.5 en local (CI en 8.4), SQLite (`brocante.db`, `data/<slug>.db`, `data/saas/*.db`) |
| Dépendances | Composer : `ovh/ovh`, `phpoffice/phpspreadsheet`, `phpunit` 13 (dev). Le travail Fichero en cours ajoute `tecnickcom/tc-lib-barcode` et `tc-lib-color` |
| Front | PHP rendu côté serveur, JS natif, pas de bundler ; `assets/vendor/` pour les bibliothèques (ex. `qrcode-generator.js`) |
| Local | **Laravel Herd** : `http://brocante.test`, `http://brocenstock.test/portail/`, sous-domaines `<commerce>.brocenstock.test`. Ne jamais lancer de serveur soi-même (`LocalValetDriver.php` reproduit les règles du `.htaccess`) |
| Hébergement | OVH mutualisé, déploiement FTP/SSH par script |
| Intégrations | Stripe (+ Connect), SumUp (page hébergée + terminal Solo), Wero via Stripe, Revolut, virement SEPA, Monetico/PayPal (retours), Gemini (IA texte/image), Modal/fal.ai/SiliconFlow (détourage, vidéo), Notion (retours testeurs → jalons), OVH API (domaines), GitHub Actions (sonde de surveillance) |
| Serveur MCP | `serveur-mcp` : piloter catalogue, contenus, réglages, remises, promos depuis Claude (OAuth pour connecteurs personnalisés) |

## 3. Carte du dépôt

```
index.php, boutique.php, produit.php, panier…   pages publiques d'une boutique
admin/                  administration d'un commerce (catalogue, studio, étiquettes, caisse, comptes, IA…)
portail/                administration de la plateforme (+ portail/todo, portail/vente, portail/recrutement)
includes/               logique métier (≈ 150 fichiers : tenant, payments, gallery-*, connect, commissions, exchange, cta, fichero…)
packs/                  plugins activables boutique par boutique (communication, rendez-vous, publication-rs…)
tenants/<slug>/         tenant.php + seed-data.json par commerce ; tenants/_modele = modèle
galerie/, accueil/, annuaire/, aide/, inscription/, marche-*/…   pages publiques de la plateforme
api/, oauth/, notifications/, connecteurs…       API et connecteurs externes
scripts/                release.sh, preflight.sh, smoke*.sh, exchange-baseline.php, tests de recette PHP
tests/                  PHPUnit (≈ 600 tests) + tests JS (node --test)
data/, var/, uploads/, backups/   données runtime (jamais servies en HTTP ni versionnées)
docs/                   SUIVI_TACHES.md, messagerie-portail.md, branding-et-domaines.md, applis-mobiles-decoupage.md
*.md racine             un document par module : BUVETTE, COMPTAGE, INVENDUS_MODULE, ANNULATION, NOTIFICATIONS, CONNECTEURS, IMPRESSION_MODULE, AUTORISATIONS…
```

Résolution du commerce affiché (`includes/tenant.php`) : variable `TENANT` → sous-domaine → premier segment d'URL (`bricbrok.com/<slug>/`) → fichier `.tenant` → `petit-chalet`.

## 4. Environnements et mise en ligne

| | local | préprod | prod |
|---|---|---|---|
| Adresse | `brocante.test` (Herd) | `https://pre.bricbrok.com` | `https://www.bricbrok.com` |
| Paiements | clés de test | clés de test | clés live (saisies sur le serveur) |
| Robots | `noindex` | `noindex` | **fermé** tant que `includes/indexation.php` = `false` (« go indexation » de l'utilisateur pour ouvrir) |

Circuit (détail dans `DEPLOIEMENT.md`) : branche → tests verts → PR vers `main` → CI verte → `scripts/release.sh preprod --portail` → validation manuelle de l'utilisateur → `scripts/release.sh prod --portail --confirm-prod` (la confirmation est le clic d'approbation de l'application ; ne jamais le contourner). Tags `preprod-AAAA-MM-JJ-HHMM` et `prod-…`.
Mots-clés de l'utilisateur : **« go preprod » / « go prod »** = déployer ; prod toujours confirmée par la carte d'approbation.

**Dernier état connu (2026-11-22)** : préprod `preprod-2026-11-22-1133` = `main` = `9f8006c` (PR #89). Prod `prod-2026-11-22-0922` = `1c06bad` : elle n'a pas encore les dernières adresses `<boutique>.aux-puces.com` (PR #88 et #89) ; mise en prod à demander (« go prod »).

## 5. Règles à ne pas enfreindre

- **Ne jamais déployer** (FTP, lftp, `deploy-*.sh`, `release.sh`) sans demande explicite de l'utilisateur. Ne jamais utiliser `--with-db` en prod.
- **Ne jamais lire, afficher ni modifier** les secrets : `.env*`, `.env.deploy*`, `.secrets/`, `config.local.php`, jetons. Pas de journaux de déploiement bruts conservés.
- **Sauvegarder avant écriture risquée** : base SQLite, `admin.html`, `.htaccess` → `fichier.bak-$(date +%Y%m%d-%H%M%S)`. Les `*.bak-*`, `*-old`, `*copie*` sont des archives : ne pas les éditer.
- Travailler sur **une branche à part**, jamais sur `main` ; le dossier est partagé entre sessions : utiliser un `git worktree` pour s'isoler. Ne pas fusionner de sa propre initiative.
- Fin de travail : `vendor/bin/phpunit` vert, commit, push, PR vers `main`, CI verte. Conclure par « PR n° X prête, CI verte » + ce qu'il faut tester en préprod.
- **Échange prod/préprod** (`includes/exchange.php`) : liste blanche. Toute nouvelle table SQL, clé de `tenant.php`, clé de galerie, réglage `settings` ou fichier annexe de galerie doit être soit ajouté aux constantes `EXCHANGE_*` (si c'est du contenu de présentation), soit laissé de côté volontairement ; dans tous les cas lancer `php scripts/exchange-baseline.php` et commiter `tests/fixtures/exchange-baseline.json` (sinon `ExchangeGuardTest` casse la CI). Jamais d'échange de commandes, clients, comptes, secrets, abonnements.
- **Gel du catalogue** : aucun nouveau pack avant trois pilotes payants.
- Cartographie : pas de tuiles OpenStreetMap (compte banni) ; IGN Géoplateforme, repli CARTO/Esri.
- Droits de fichiers en ligne : 604 (fichiers) / 705 (dossiers).
- Préférence de l'utilisateur : **réponses en français, courtes, sans jargon** ; ne pas lui demander d'ouvrir un terminal si l'application peut le faire.
- Vérifier chaque modification sur le site local avant d'affirmer qu'elle fonctionne.

## 6. Ce qui est livré (en prod ou prêt sur `main`)

Chronologie récente (détail dans `git log` et le journal de `docs/SUIVI_TACHES.md`) :

- **Galeries** : types (dont Marché de Noël et Marché des producteurs), plan d'implantation avec export PDF 300 dpi / PSD, réservations (arrhes), file d'attente, horaires + alerte de fermeture, messagerie interne, fidélité/espace visiteur, sponsors & partenaires, sous-zones (branche `sous-zones-galerie`).
- **Extensions de galerie** : Buvette (carte, recettes, courses, check-list, bénévoles), Invendus & dons (tournées, bordereaux, page publique `/dons.php`), Comptage de foule, Annulation & remboursements.
- **Paiement** : Stripe + **Stripe Connect** (code en prod, commissions), SumUp (page hébergée et terminal Solo), Wero, Revolut, virement SEPA.
- **Caisse** : vente express, avis de l'expert, rôle vendeur, journal des ventes enrichi ; journal des dépenses (achats, factures, notes de frais).
- **Plateforme** : retours testeurs (widget, interrupteurs par espace, exceptions par rôle) avec import automatique vers les tâches du portail et vers Notion ; module Tâches `/portail/todo/` ; recherche globale ⌘K (portail + admin) ; laboratoire d'appels à l'action (A/B) ; module Équipe ; Rendez-vous (pack `rendez-vous`) ; publication réseaux sociaux (pack indépendant `publication-rs`) ; centre d'aide `/aide/` avec éditeur de tutoriels dans le portail ; impact CO₂/eau/numérique ; logo en trois déclinaisons + favicon pour le portail ; serveur MCP + OAuth ; prospection avec audit des sites et réseaux sociaux ; annonces avec publication automatique locale depuis le navigateur du vendeur (dernier commit de la branche) ; page de vente aux-puces.com.
- **Étiquettes Fichero D11s** (impression Bluetooth depuis le navigateur) : **entièrement fusionné dans `main`** (PR #78, #79 et #80 : QR, nom/prix/réf, contraste, reconnexion Chrome, aperçu fidèle, Data Matrix, impression sur mobile).
- **Depuis le 2026-11-13 (PR #82 à #89)** : page de vente aux-puces.com ; hall/bâtiment du commerce dans la navigation et les cartes de galerie (#83) ; grille des places masquée quand la réservation est fermée (#84) ; visite guidée des marchés aux puces par hall (#86) ; sélection des photos de départ conservée au rechargement (#87) ; adresses `<boutique>.aux-puces.com` pour les boutiques des Puces de Villeurbanne, la galerie `lespuces` et `petit-chalet` changent d'adresse (#88, #89).
- **Qualité** : 600 tests PHPUnit (2 834 assertions) **verts** sur l'arbre local, 9 tests JS (`node --test tests/fichero-print.test.js`) verts. CI : syntaxe PHP, PHPUnit, contrôle des secrets (`.github/workflows/ci.yml`).

## 7. Travail en cours (au 2026-11-22)

Le travail Fichero décrit dans la version du 16/11 est **terminé et fusionné dans `main`** (PR #80) : ne plus le reprendre depuis les copies locales anciennes de `claude/changes`.

État de l'arbre local sur `claude/changes` (50 commits de retard sur `main`) :

| Fichier | État |
|---|---|
| `admin/gallery.php` | modifié, non commité : mémorise la sélection des photos de départ (`localStorage`). Même fonction déjà fusionnée dans `main` (PR #87) : **ne pas commiter**, abandonner ou comparer avant. |
| `tenants/petit-chalet/tenant.php` | modification **locale d'identifiants de test**, jamais à commiter (restaurer avec `git checkout -- tenants/petit-chalet/tenant.php` avant de livrer ; ne pas afficher la valeur). |
| `ETAT_DEVELOPPEMENT.md` | ce document, non suivi. |

PR ouvertes (CI verte, non fusionnées) :

- **#85** `claude/mdp-admin-lien` : lien de nouveau mot de passe envoyé à l'admin d'une boutique.
- **#81** `sms-portail-notifications` : le canal SMS des notifications reprend le compte SMS du portail.

À faire côté mise en ligne : la prod (`1c06bad`) est un cran derrière `main` (`9f8006c`) ; elle n'a pas les adresses `<boutique>.aux-puces.com` des PR #88 et #89. À déployer sur « go prod » de l'utilisateur.

Pour reprendre une tâche : partir d'une branche neuve issue de `main` à jour (`git fetch && git switch -c <ma-branche> main`) plutôt que de `claude/changes`.

Reste à valider : la lecture du Data Matrix sur une vraie Fichero D11s (Web Bluetooth : Chrome ordinateur ou Android, HTTPS ; pas d'iPhone → exporter les images ; limite de 50 pièces par export ou impression).

## 8. Autres branches et worktrees ouverts

Branches non fusionnées dans `main` (au 2026-11-22) : `claude/mdp-admin-lien` (PR #85), `sms-portail-notifications` (PR #81), `serveur-mcp` (MCP : corbeille réversible, frais de port, prix d'achat, suivi de commande, photos, campagnes de promo), `sous-zones-galerie`, et `claude/changes` (cette branche). `agents/etiquettes-data-matrix` est fusionnée (PR #80).
Le dépôt compte environ 36 worktrees (`.claude/worktrees/`), dont beaucoup correspondent à des PR déjà fusionnées : à nettoyer (`git worktree prune` / suppression des dossiers) seulement sur demande.
Autres dossiers du « web local » (louxor, electroboy80, antiqua…) sont **indépendants** : ne pas y toucher depuis ce dépôt.

## 9. Backlog produit (extrait de `docs/SUIVI_TACHES.md`, relu le 2026-11-10 ; journal complété le 2026-11-22)

> Aucun commit lié à #12, #18 ni #20 depuis le 10/11. L'échéance de #18 (21/11) est dépassée : l'état réel de l'activation Stripe Connect est à confirmer par l'utilisateur. Prochaine échéance : #20 le 30/11.

| # | Tâche | État |
|---|---|---|
| 5 | Pitch vidéo pour les proches | script livré, tournage à faire |
| 6 | Trouver les maisons à vider | à faire |
| 10 / 11 | Événement de particulier (vide-dressing / maison) puis fédéré/itinérant | à faire (modèle de données d'abord) |
| 12 | **Disclaimer objets d'occasion** (fiche produit, panier, CGV) | à faire, P1, petit lot |
| 14 | Plan en PDF : variante affiche A4/A3 + encarts publicitaires | en cours |
| 15 | Centre d'aide : captures et vidéos, widget dans portail/admin | en cours |
| 16 / 17 | Commerces d'une rue (Google Places) puis façade (Street View) | à faire, clé API + quota |
| 18 | Stripe Connect : activer l'app, brancher les comptes réels, recetter un paiement avec commission et un premier versement | en cours, P1 (21/11/2026) |
| 19 | Alternative à Stripe : SEPA + Revolut codés, recette réelle à faire ; Mollie Connect recommandé en secours | en cours, P2 (15/12/2026) |
| 20 | Montée en charge OVH (paliers, mesures, préprod avant la 10ᵉ boutique) | à faire, P1 (30/11/2026) |

Retours testeurs ouverts (boutique « La friperie de naty ») : parcours photo / shooting (ouverture de l'appareil, assistance vocale dès le premier écran, recto d'analyse repris dans le parcours, focale iPhone, message du footing audio, article par défaut du Fitting photo) et **choix du rôle à la connexion** pour un compte multi-rôles (`admin/accounts.php`). Autres idées P2 : statistiques/notation de l'IA (livré), tableau de bord frais/CA par mois, pack antiquaire (gelé), ventes flash dans le pack actions commerciales, étiquettes et recalcul des marges.

## 10. Commandes utiles

```bash
cd "/Users/admin/Documents/projet/web local/brocante"
vendor/bin/phpunit                                  # suite PHP (≈ 8 s, 600 tests)
node --test tests/fichero-print.test.js             # tests JS Fichero
php scripts/exchange-baseline.php                   # régénérer la ligne de base de l'échange
scripts/release.sh preprod --portail --dry-run      # liste ce qui partirait (n'écrit rien)
scripts/smoke-remote.sh https://pre.bricbrok.com preprod
php -l fichier.php                                  # contrôle de syntaxe
```

## 11. Pour démarrer vite

1. `git fetch && git switch -c <ma-branche> main` (ou `git worktree add` pour s'isoler).
2. Lire `CLAUDE.md`, `DEPLOIEMENT.md`, la section du `README.md` qui concerne le sujet et le `*.md` du module.
3. Prendre une tâche du backlog (§ 9) ou fusionner les PR #81 et #85 sur demande. Priorités datées : #18 (échéance du 21/11 dépassée), #20 (30/11), #12 (petit lot rapide).
4. Tester sur `*.test` via Herd, `vendor/bin/phpunit`, PR vers `main`. **Ne pas fusionner ni déployer sans demande explicite.**
