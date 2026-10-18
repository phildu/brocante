# Boutique en marque blanche

Boutique en ligne PHP + SQLite (sans framework ni serveur de base de données),
créée pour **La Brocante du Petit Chalet** et réutilisable pour n'importe quel
commerce. Tout ce qui est propre à un commerce est regroupé dans :

```
tenants/<slug>/tenant.php        nom, URL, mot de passe admin, couleurs, catégories,
                                 textes de l'accueil, contexte pour l'IA, base SQLite
tenants/<slug>/seed-data.json    contenu initial : accueil, histoire, contact, produits
assets/tenants/<slug>/           logos
```

Commerces fournis :

| Dossier                        | Rôle                                                          |
|--------------------------------|---------------------------------------------------------------|
| `tenants/petit-chalet`         | La Brocante du Petit Chalet (site actuel, inchangé)           |
| `tenants/exemple-librairie`    | Exemple de second commerce (couleurs, rayons et textes propres) |
| `tenants/_modele`              | Modèle copié pour chaque nouveau commerce                     |

## Quel commerce est affiché ?

1. la variable d'environnement `TENANT` ;
2. sinon le sous-domaine, s'il porte le nom d'un commerce (`naty.brocenstock.test` → `tenants/naty`) ;
3. sinon le premier segment de l'adresse, s'il porte le nom d'un commerce
   (`brocs.arrimage.com/naty/boutique.php` → `tenants/naty`) ;
4. sinon le fichier `.tenant` à la racine (écrit sur le serveur par le déploiement) ;
5. sinon `petit-chalet`.

### Adresses en ligne

| Adresse                                | Affiche                                                      |
|----------------------------------------|--------------------------------------------------------------|
| `brocs.arrimage.com`                   | le portail (redirigé vers `/portail/`, connexion exigée)     |
| `brocs.arrimage.com/<identifiant>/`    | le commerce `tenants/<identifiant>` (aucun sous-domaine à créer chez OVH) |
| `brocante.arrimage.com`                | le Petit Chalet, déployé à part avec son propre sous-domaine |

Un commerce servi sous `/<identifiant>/` voit ses liens, formulaires et
redirections préfixés automatiquement (`includes/tenant.php` :
`tenant_rewrite_output()`, `tenant_rewrite_location()`) ; `assets/` et `uploads/`
restent partagés à la racine du domaine. Son panier et sa connexion admin ont leur
propre cookie de session, limité à son chemin (`includes/session.php`). Les règles
d'adresses sont dans le `.htaccess` ; en local sans Apache, `php -S 127.0.0.1:8000 router.php`
les reproduit. L'adresse d'un commerce se déclare par `site_url` dans son `tenant.php`
(`https://brocs.arrimage.com/<identifiant>` pour un commerce sous le portail ; elle sert
notamment aux retours de paiement Stripe).

Le Petit Chalet garde ses emplacements historiques (`brocante.db`, `.secrets/`,
`assets/logo.png`) : le site en ligne et `config.local.php` fonctionnent comme avant.
Les autres commerces ont leur base dans `data/<slug>.db` et leurs clés dans
`.secrets/<slug>/`.

## Tester en local avec Herd : une adresse par commerce

`herd link brocenstock` dans ce dossier, puis :

| Adresse                                     | Affiche                                        |
|---------------------------------------------|------------------------------------------------|
| http://brocenstock.test/portail/            | le portail des commerces                       |
| http://naty.brocenstock.test                | le commerce `tenants/naty`                     |
| http://exemple-librairie.brocenstock.test   | la Librairie des Quais                         |
| http://brocenstock.test                     | le portail (redirection, comme en ligne) |
| http://brocenstock.test/exemple-librairie/  | la Librairie des Quais sous le domaine du portail (comme `brocs.arrimage.com/<commerce>/`) |
| http://brocenstock.test/galerie/<id>/       | une galerie commerciale (`/galerie/` : la liste des galeries publiées) |

Le sous-domaine est l'identifiant du commerce (nom de son dossier dans
`tenants/`) ; un sous-domaine inconnu affiche le commerce par défaut.

**`LocalValetDriver.php`** (à la racine) apprend à Herd — qui tourne sous nginx et ne lit pas le `.htaccess` — les mêmes règles
d'adresses que la ligne : racine → portail, `/galerie/…`, `/<commerce>/…`, et il refuse (403) `data/`, `tenants/`, `includes/`,
`var/`, `scripts/`, les fichiers cachés (`.secrets/`…) et les `*.db`, `*.sql`, `*.log`, `*.bak`, qui seraient sinon téléchargeables en
local. Il n'est jamais envoyé en ligne (exclu du déploiement). Sans Herd : `php -S localhost:8000 router.php` (mêmes règles).

Le portail liste les commerces avec leur adresse, affiche la boutique choisie
(accueil, boutique, panier, administration, vue mobile), réinitialise les
données de démonstration et crée un nouveau commerce depuis un formulaire
(identité, logo, couleurs, catégories, textes, premiers produits). Il écrit
dans le dossier du projet : il ne répond qu'en local (hôte `.test`,
`localhost` ou `127.0.0.1`) et n'est jamais déployé. Pensez à ajouter à git
les dossiers `tenants/<slug>` et `assets/tenants/<slug>` qu'il crée.

**Supprimer un commerce.** Sur la carte de chaque commerce du portail, « Supprimer »
demande de retaper son identifiant. Rien n'est effacé : `tenants/<slug>`,
`assets/tenants/<slug>`, sa base SQLite et ses clés (`.secrets/<slug>/`) sont déplacés
dans `data/corbeille/<slug>-<date>/` (jamais servi en HTTP) ; pour le restaurer, remettre
les dossiers à leur place. Les photos de `uploads/` (partagées) restent en place. Le
Petit Chalet et les modèles (`_modele`) ne se suppriment pas depuis le portail. La
suppression ne vaut que pour le serveur où elle est faite : un commerce supprimé en
ligne mais présent dans `tenants/` sur le Mac est recréé, vide, au prochain
`./deploy-brocante.sh --portail` — le supprimer aussi en local (et dans git).

**Contenu généré par l'IA.** En haut du formulaire de création, le champ « Générer
le contenu avec l'IA » prend une description du commerce (thématique, ce qui se
vend, ton) et remplit slogan, catégories, textes de l'accueil, premiers produits,
palette (assombrie si elle manque de contraste) et polices, ainsi que le contexte
donné à l'IA pour décrire les photos (`ai` du `tenant.php`). Le nom saisi est
conservé ; identifiants d'administration, logo et adresse du site ne sont jamais
touchés ; adresse postale et horaires restent vides. Tout est relisible avant de
créer. Il faut une clé Gemini : `.secrets/gemini.key` à la racine du portail (le
formulaire propose de la coller et l'enregistre s'il n'y en a pas), ou la variable
`GEMINI_API_KEY`.

## Apparence (couleurs et typographie)

Dans l'administration de chaque commerce, page **Apparence** : palettes toutes
prêtes ou 4 couleurs au choix (fond, texte, principale, secondaire), polices
des titres et des textes parmi une sélection Google Fonts, aperçu en direct
(clair et sombre) et indicateur de contraste. Les nuances intermédiaires et le
thème sombre sont calculés automatiquement (`includes/appearance.php`).
La section **Logos** de la même page accepte trois déclinaisons (PNG, JPG,
WebP ou SVG sans script) : **horizontal** (en-tête, administration),
**vertical** (écran d'accueil mobile ; à défaut, le carré) et **carré**
(macaron de l'accueil, menu replié, icône d'onglet). Sans logo envoyé, ceux
du `tenant.php` (`logo`, `logo_macaron`) s'appliquent.
L'apparence est enregistrée dans la base du commerce (table `settings`) et
prime sur les couleurs et polices de son `tenant.php` ; « Revenir à
l'apparence d'origine » l'efface.

## Ajouter une pièce depuis un smartphone

Administration → **Nouvelle pièce (photo)** (`admin/quick-add.php`), pensé pour
le téléphone : un cadre par angle (face, profil, dos, détail, dessous, autres)
qui ouvre directement l'appareil photo, photos réduites sur le téléphone avant
l'envoi, puis, étape par étape (`admin/quick-add-action.php`) : détourage de la
photo principale, mise en situation et rédaction de la fiche d'après **tous les
angles** (+ indications facultatives du vendeur). La fiche se relit et se
corrige sur le téléphone, puis s'enregistre masquée ou publiée. Sans clé
Gemini / fal.ai, les photos sont enregistrées et la fiche se remplit à la main.

## Consommation de l'IA : coûts estimés et solde

Chaque appel payant est consigné dans la table `ai_usage` (`includes/ai-usage.php`) avec son **coût estimé** : image générée
(Gemini, tarif à l'unité), texte et vision (d'après le nombre réel de tokens renvoyé par l'API, réflexion du modèle
comprise), recherche web (tarif d'une requête avec recherche, plus les tokens), détourage fal.ai. La page **Consommation IA**
(`admin/ai-usage.php`, menu de l'administration) donne la dépense d'aujourd'hui, des 7 derniers jours, du mois et le **total
évalué**, le détail par type, par mois et par pièce, les derniers appels (avec leur origine) et le coût estimé de chaque
opération. Le menu affiche en permanence « IA ce mois » ou, si vous avez déclaré un crédit, « Solde IA ».

**Solde.** Déclarez le crédit prépayé disponible (Google Cloud, fal.ai) : le solde estimé est ce crédit moins les dépenses
consignées depuis (barre de progression, alerte sous 15 %). « Recaler le solde » repart d'un nouveau montant — à utiliser
quand on recharge ou quand on lit le vrai solde chez Google ou fal.ai. **Ce solde est une estimation** : aucune API ne
donne le solde réel d'un compte, et les quotas gratuits ne sont pas déduits.

**Tarifs.** Ceux des offres publiques à la date de cette version (image 0,039 $, texte 0,30 $ / 2,50 $ le million de tokens,
recherche web 0,035 $ la requête, détourage fal.ai 0,001 $, dollar à 0,92 €), modifiables dans la page ; les appels déjà
consignés gardent le coût calculé à l'époque. Les estimations sont annoncées là où l'on déclenche l'IA : galerie (« Coût
estimé ≈ 0,07 € : image 3:2 + version 9:16 »), Studio (coût du lot, par pièce), ajout rapide, bouton « Marché ». Seuls les appels
faits depuis la mise en service du suivi sont comptés ; le portail de création de boutiques a son propre appel Gemini, non suivi.

## Gestion du prix de vente : prix affiché, minimum, fourchette, négociable, prix du neuf

Dans la fiche du catalogue, le bloc **Gestion du prix de vente** complète le champ **Prix affiché** (celui que voit et paie le client, inchangé : panier, promos, galeries) :

- **Prix de vente minimum** (`price_floor`) : **interne, jamais affiché** ; il sert à juger les offres (« au-dessus / sous le minimum ») et la marge. Un minimum supérieur au prix affiché est ignoré, avec un message.
- **Fourchette estimée** (`est_min` / `est_max`) : remplie en un clic depuis la recherche « Marché » (bouton « Appliquer la fourchette estimée »), avec une case **« Afficher sur la fiche »** (« Estimation du marché (occasion) : 20 € à 35 € »).
- **Prix négociable** (`negotiable`) : la fiche affiche « Prix négociable » et un bouton **« Faire une offre »** (fenêtre : offre, nom, e-mail, message → `offre.php`). L'offre est enregistrée (`price_offers`), les administrateurs de la boutique sont **prévenus par e-mail**
  (avec le rappel du minimum interne) et la retrouvent dans **Administration → Offres de prix** (répondre par e-mail, marquer traitée, supprimer ; le menu indique le nombre d'offres à traiter). Rien n'est débité : le client achète ensuite au prix convenu, que le vendeur applique à la fiche.
  Garde-fous : champ piège anti-robot, 5 offres par heure et par visiteur, doublons ignorés, offre ≥ prix affiché refusée.
- **Prix du neuf et lien** (`new_price`, `new_url`) avec une case **« Afficher le prix et le lien du neuf sur la fiche »** (lien « Voir le prix du neuf (site) ↗ », en `nofollow`). Le panneau de la recherche « Marché » propose **« Chercher le lien du prix du neuf »** : une recherche web à part ouvre les trois premières pages marchandes
  (les redirections de Google sont suivies aussitôt, c'est l'adresse finale qui est gardée) et propose la première où un prix du neuf plausible est retrouvé (« page ouverte : prix retrouvé ✓ »), sinon une page marchande à contrôler. Rien n'est enregistré sans le clic « Utiliser ce lien du neuf » puis l'enregistrement de la fiche.

## IA des formules : quota gratuit, calculateur de coûts, rachat de crédit

Toute la logique est dans `includes/ai-plans.php` (autonome : portail, inscription et boutiques l'utilisent) ; fichiers dans `data/saas/` : `ai-costs.json` (barème et packs), `ai-policy.json` (quota de chaque boutique), `ai-credits.json` (crédit racheté).

- **Portail → Coûts IA** (`portail/ia.php`) : **1. Barème** des coûts (dollar → euro, texte, image, recherche, détourage, vidéos Veo / Wan / Modal) avec les coûts de référence en euros ; **2. Calculateur de forfait** :
  on saisit un nombre de fiches complètes, de textes, d'images, de vidéos de 6 s et de recherches de prix par mois, il donne le **coût** de cette IA offerte et son **équivalent** en textes / images / vidéos, la **marge brute** de l'offre visée, et l'**affecte**
  à une offre (formule ou tranche, tarifs par défaut ou d'une galerie) ou à une boutique existante ; un tableau montre le quota et la marge de chaque offre ; **3. Packs de crédit** à racheter (nom, crédit en euros de consommation, prix payé, marge) ;
  **4. Boutiques** : quota, consommation du mois, crédit restant, état (active / suspendue), avec « affecter un quota », « offrir du crédit » et « illimitée ».
- **Quota dans les formules** : colonne **« IA offerte / mois »** de Portail → Tarifs (euros de consommation ; vide = IA non limitée, 0 = aucune IA incluse, crédit à acheter), avec l'**équivalent estimé** en direct. Le quota est un budget mensuel unique, que la boutique dépense en textes, images ou vidéos ;
  les offres l'affichent (« IA incluse chaque mois, au choix : ≈ 1 315 textes · ≈ 134 images · ≈ 8 vidéos de 6 s ») sur l'accueil, l'inscription et l'aperçu des tarifs. À l'inscription, le quota de l'offre choisie est affecté à la nouvelle boutique (`saas_generate_shop()`).
- **Décompte et suspension** (`ai_quota_state()`, `ai_gate()` dans `includes/ai-usage.php`) : la consommation du mois est mesurée au **barème de la plateforme** ; le dépassement du quota (cumulé depuis le début du décompte) est prélevé sur le **crédit racheté**, qui n'expire pas.
  Sans quota restant ni crédit, les générations IA (images, textes, détourage, recherche de prix, vidéo, Studio, import par lot, boutons « ↻ IA ») répondent par un message clair au lieu d'appeler l'IA. Une boutique sans quota affecté n'est pas limitée (comportement d'avant).
- **Rachat de crédit** : dans **Consommation IA** de la boutique, un bloc montre le quota du mois (barre, reste, équivalent), le crédit restant et les **packs** à acheter (paiement unique Stripe sur le compte de la **plateforme** : `admin/ai-credit-action.php`, retour `admin/ai-credit-merci.php`, et webhook
  `checkout.session.completed` de `inscription/webhook.php`). Le crédit est ajouté une seule fois par session de paiement (idempotent) ; l'achat apparaît dans le journal d'activité. Sans Stripe configuré sur le serveur, la page invite à contacter l'exploitant.
  Le pied du menu d'administration affiche « IA restante : X € » (ou « IA suspendue »).

## Visuels promo et compteur des mises en avant

Pour un article en promo (mention « Promo » + prix promo enregistrés), le bouton **🎨 Générer le visuel promo** de sa fiche (catalogue) ouvre une fenêtre qui fabrique, **dans le navigateur**
(canvas, avec les couleurs et les polices de la boutique : `assets/promo-visual.js`), la photo choisie avec un **encart en overlay** — pastille de remise (« −30 % », modifiable), légende, **prix promo** et ancien
prix barré, **bouton d'appel à l'action** (« Voir l'offre », modifiable) — en deux formats : **3:2** (ordinateur) et **9:16** (smartphone). `admin/promo-visual-action.php` les range (`uploads/promo-…`, table `promo_visuals`)
avec une copie des prix affichés : si le prix de l'article change, le visuel est **périmé** et n'est plus montré tant qu'il n'est pas refait (`promo_visual_fresh()`).

Il s'affiche :
- sur la **page d'accueil** de la boutique (section « À saisir en ce moment », pour les trois modèles de page : `promo_section_html()`) ;
- dans les **promos de la galerie commerciale** dont la boutique fait partie (section « Les promos du moment » de `galerie/index.php`, reprise du catalogue de la galerie : les visuels apparaissent au plus tard après les 10 minutes de cache).

**Compteur** (`includes/promo.php`, base `data/saas/promo.db`, par jour / boutique / article / emplacement) : un **affichage** est compté une fois par visite quand au moins la moitié du visuel est visible pendant une seconde
(`assets/promo-track.js` → `promo-track.php`) ; un **clic** passe par `promo-click.php` (accueil) ou `galerie/clic.php` (galerie, destination lue dans le catalogue de la galerie : pas de redirection libre) avant d'ouvrir la fiche.
Chaque emplacement porte un **jeton** (HMAC, `data/saas/promo.secret`) : sans lui, rien n'est compté ; robots exclus, 240 événements par minute et par adresse au plus ; l'aperçu d'une galerie non publiée n'est pas compté.
Les chiffres se lisent dans le back-office de la boutique (**Mises en avant (promos)** : par article et par emplacement, 7 j à 12 mois, export CSV ; et sur la fiche de l'article) et, pour l'exploitant, dans le portail
(**Mises en avant** : par galerie, par boutique, par article, export CSV) — de quoi facturer ces mises en avant (tarif par affichage ou par clic, à définir). Les compteurs sont ceux du déploiement qui sert la page.

## Mot de passe oublié et connexion par les réseaux sociaux (administration et Studio)

- **Mot de passe oublié** : lien « Mot de passe oublié ? » sous la connexion de l'administration et du Studio (`admin/mot-de-passe-oublie.php`). Un lien à usage unique, valable 1 heure, est envoyé par e-mail
  (`admin/reinitialiser.php` : nouveau mot de passe de 8 caractères au moins, avec confirmation). Jeton haché en base (table `password_resets`), réponse toujours identique (on ne révèle pas si un compte existe), limites
  de demandes par compte et par adresse IP. Un compte de l'équipe reçoit le lien sur son e-mail. Le **compte principal** (`admin_user` de `tenant.php`) le reçoit sur son identifiant s'il s'agit d'une adresse e-mail, sinon
  sur `admin_email` de `tenant.php`, sinon sur un administrateur de l'équipe ; son nouveau mot de passe est gardé dans les réglages de la base et cesse de valoir si `tenant.php` change de mot de passe (portail).
  En local (hôte `localhost`, `.test`, adresse IP), les e-mails sont aussi écrits dans `data/mail-outbox.log`. Le code est dans `includes/password-reset.php`.
- **Réseaux sociaux** : les boutons « Continuer avec Google / Facebook / Microsoft / Apple » (fournisseurs activés dans Administration → Connexion sociale) s'affichent maintenant aussi sur la connexion du Studio
  (`oauth/start.php` et `oauth/finish.php` : paramètre `next=/studio.php`, retour et messages d'erreur sur le Studio, téléphone gardé connecté 30 jours).

## Agrandir et télécharger une image

`assets/lightbox.js` (+ `lightbox.css`), chargé par le pied de page de la boutique et par les pages d'administration à images (galerie, médiathèque, catalogue, promos, bandeaux, diaporamas) :
au survol d'une image (ordinateur), deux boutons apparaissent dans son coin, **⤢ Agrandir** (fenêtre plein écran) et **⬇ Télécharger** (le fichier tel qu'il est publié). Dans le diaporama d'une fiche
et les listes de la galerie, ◀ ▶ (ou les flèches du clavier) passent d'une image à l'autre ; Échap, la croix ou un clic à côté referment. Le script repère lui-même les images d'au moins 110 × 80 px ;
`data-no-lightbox` (sur l'image ou un parent) l'exclut, `data-full` indique une version plus grande. Sans survol (tablette, smartphone), rien ne change.

## Sélecteur du diaporama

Dans la galerie d'une pièce (`admin/gallery.php`), le bloc **Diaporama de la fiche** montre une vignette par image ou vidéo : un clic l'ajoute au diaporama ou l'en retire, un glisser-déposer (ou ◀ ▶ / les flèches du clavier)
change l'ordre, la première étant l'image principale. L'enregistrement est automatique (`admin/diaporama-action.php` : `is_hidden` et `sort_order`, une image au moins reste montrée, la photo de couverture du catalogue est recalculée).
Raccourcis : « Seulement les images générées » (et les gros plans étiquette / texture / détail) et « Tout afficher » (hors mesures). Les vignettes indiquent « IA », « vidéo » et, pour un visuel qui n'existe
qu'à un seul format, « ordinateur » ou « smartphone ».

## Couleurs

Champ `products.colors` (colonne ajoutée automatiquement) : les **couleurs dominantes** de l'objet, en noms français séparés par des virgules (« bleu marine, blanc cassé », 6 au plus, normalisées par
`product_colors_clean()`). Saisie dans le catalogue (modification et ajout) et dans l'ajout rapide / le Studio, avec le bouton « ↻ IA » (et la rédaction automatique de la fiche, qui les lit sur les photos).
La **fiche publique** (trois modèles) les affiche avec une pastille de couleur quand le nom est connu (`product_colors_html()`), la recherche de la boutique les prend en compte et elles
alimentent le contexte de la recherche de prix.

## Marque et distributeur

Deux champs de la fiche article (`products.brand`, `products.distributor`, colonnes ajoutées automatiquement aux bases existantes) : la **marque** (Levi's, Olivetti, Le Creuset…, lue sur l'objet ou son étiquette)
et le **distributeur** (éditeur, importateur ou revendeur d'origine). Ils figurent dans le formulaire du catalogue (modification et ajout), dans l'ajout rapide / le Studio (relecture), sur la **fiche publique**
(« Marque », « Distributeur », pour les trois modèles de page) et dans la **recherche** de la boutique. L'IA lit la marque sur les photos (bouton « ↻ IA » du champ, et rédaction automatique de la fiche) sans jamais
en deviner une d'après le style seul ; elle sert aussi à la recherche de prix. Le scan d'un code-barres remplit le distributeur (éditeur d'un livre, label d'un disque) et la marque d'un article du commerce (UPCitemdb).

## Prix numériques et « Mettre en promo »

Les champs **Prix** du back-office (fiche du catalogue, ajout, relecture du Studio et de l'ajout rapide, tarifs de livraison) sont des **champs numériques en euros**
(`<input type="number" step="0.01">`, plus de « € » à saisir). Le texte reste enregistré tel quel dans la base (`25 €`, `25,50 €`) : `price_from_input()` met en forme la valeur
envoyée ; un champ laissé vide **conserve** l'ancien prix ; un ancien prix non numérique (« 25-35 € », « sur devis ») s'affiche en note sous le champ jusqu'à ce qu'on saisisse un montant.
Ce que l'IA ou la recherche de prix écrit dans le champ (« 25-35 € » → 30, « 24 € » → 24) est converti en montant (`assets/price-field.js`, `price_single()` côté serveur pour les prix indicatifs).

Dans la fiche du catalogue, le bouton **🏷 Mettre en promo** à côté du prix donne la mention « Promo », affiche le **prix promo** (−20 % proposé), des remises rapides **−10 / −20 / −30 / −50 %**
et le pourcentage de remise ; **✕ Retirer la promo** remet la mention d'avant et vide le prix promo. Le prix promo doit être inférieur au prix, sinon `admin/save-product.php` l'ignore et le dit.

## Prix du marché : le même article, neuf et d'occasion

Le bouton **Marché** à côté du champ Prix (catalogue, relectures du Studio et de l'ajout rapide) lance une vraie recherche
web (`admin/price-research.php`, `includes/price-research.php`) : Gemini avec l'outil « Google Search » cherche le prix du
MÊME article — ou du plus proche —, neuf et d'occasion, en France, en s'aidant des photos de la pièce pour reconnaître marque
et modèle, de son nom, de ses matières, de sa nature, de sa taille et de son état. Le résultat s'affiche sous le champ :
l'article reconnu, un tableau **votre catalogue** (pièces similaires déjà enregistrées : fourchette et médiane) /
**neuf (web)** / **occasion (web)**, un **prix conseillé** pour CET exemplaire dans son état avec sa justification, la
fiabilité de la recherche (« faible » quand peu de prix comparables existent : l'IA doit le dire plutôt qu'inventer) et les
sources et recherches effectuées. **Marketplace d'Emmaüs** : la recherche lit aussi, côté serveur, les annonces réelles de Label Emmaüs (`label-emmaus.co`, articles d'occasion des
communautés, comités d'amis et ressourceries : `price_research_emmaus()`). Le moteur du site cherche tous les mots, donc le nom de la pièce est réduit à ses mots utiles
(5, puis 3, 2, enfin 1 mot) jusqu'à obtenir au moins 3 annonces ; les 12 premières donnent une ligne **Marketplace Emmaüs** (nombre d'annonces, fourchette, médiane) et la
liste des 6 premières (titre, prix demandé, vendeur, lien). Ces annonces sont aussi transmises à l'IA comme repères d'occasion (prix demandés, pas des ventes conclues) ;
à défaut de tout autre repère, leur médiane sert de prix conseillé. Résultat gardé un jour (une seule requête au site par recherche, `data/barcode-cache/`).
« Utiliser » écrit le prix dans le champ, sans rien enregistrer. Une recherche prend 20 à
40 secondes ; les recherches web de l'API Gemini peuvent être facturées au-delà du quota gratuit. Sans clé Gemini, seul le
tableau du catalogue est disponible.

## Le catalogue d'abord, l'IA ensuite

Avant d'estimer un prix, un poids ou des dimensions, l'IA regarde ce que la boutique sait déjà
(`includes/comparables.php`). `product_comparables()` cherche dans le catalogue les pièces **similaires** — même
sous-catégorie (+4) et nature (+2), même univers (+1), mots significatifs communs aux noms (+2 chacun) — et retient
celles de score ≥ 3. Deux usages : (1) **sans IA** pour le poids, quand au moins 3 pièces de même sous-catégorie ont un
poids et s'accordent (écart interquartile ≤ 50 % de la médiane) : le poids médian est repris tel quel, instantanément
(« poids tiré de 4 pièces similaires du catalogue (sans IA) ») ; (2) sinon les pièces similaires, avec leurs prix, poids,
tailles, matières et états, sont données à l'IA comme **référence prioritaire** (« estimé par l'IA en s'appuyant sur N
pièces similaires »). L'interface affiche la source de chaque valeur. Après la génération d'une fiche (Studio, lot, ajout
rapide), le poids du catalogue remplace l'estimation de l'IA quand il est connu (`catalog_refine_weight()`).

**Tailles de vêtements.** Pour un vêtement, une chaussure ou un accessoire porté, l'IA donne toujours une taille : celle de
l'étiquette si elle est lisible, sinon la taille la plus probable d'après la coupe, écrite « M (probable) ».

## Scripts et styles : jamais périmés après un déploiement

`.htaccess` impose `Cache-Control: no-cache` aux fichiers `.js` et `.css` : le navigateur les revalide à chaque page (ETag /
Last-Modified, réponse 304 si rien n'a changé) au lieu de garder pendant 15 minutes — durée par défaut du serveur — l'ancienne
version d'un script avec la nouvelle page, ce qui laissait des boutons sans effet juste après un déploiement.

## Univers : les grands domaines de la boutique (Mode, Sport, Déco, Alimentaire, TV-hifi, Informatique…)

Vocabulaire : l'**univers** d'une pièce est son grand domaine — Mode, Sport, Déco, Alimentaire, TV et hifi, Informatique… Il sert de
filtre dans « La boutique », d'entrée à l'accueil et de champ « Univers » de la fiche. Le détail — vêtement › pulls, chaussures ›
baskets — est la **nature** et la **sous-catégorie** de la pièce (voir plus bas). Les univers possibles sont les 16 secteurs de
`shop_sectors()` (`includes/universes.php`) : Mode, Sport, Déco, Alimentaire, TV et hifi, Informatique, Téléphonie, Électroménager,
Bijoux et beauté, Brocante, Livres et médias, Jeux et jouets, Bricolage et jardin, Auto et moto, Bébé et enfant, Animaux — chacun avec
son libellé court, sa description pour l'IA, son pictogramme, les natures de produits qui y mènent et des mots-clés qui le trahissent.

**Page Univers** (`admin/universes.php`). Des pastilles ajoutent ou retirent un univers de la boutique d'un clic ; la liste s'édite
(nom, pictogramme, ordre ; on peut aussi créer un univers personnalisé, comme les rayons d'une brocante) ; la liste enregistrée (table
`settings`, clé `universes`) remplace celle de `tenants/<slug>/tenant.php`, « Rétablir » y revient. Les clés des univers de domaine sont
celles des secteurs (`mode`, `tv_hifi`…) ; celle d'un univers renommé ne change pas : ses pièces y restent rattachées.

**Détection.** « Détecter les univers de ma boutique (IA) » — lancée d'elle-même à la première ouverture quand la boutique n'a pas de
type enregistré et que des pièces ont une photo — regarde les **photos** des pièces récentes, leurs natures, le nom et l'accroche de la
boutique, et l'indication écrite par le vendeur dans « Ce que vend votre boutique ». Les textes du site et la description technique
(souvent un modèle d'exemple : une boulangerie) sont déclarés non fiables. Si l'IA ne répond pas ou ne reconnaît rien, le repli est
déterministe : (1) la nature des pièces en vente (secteurs regroupant au moins 25 % des pièces avec nature,
`shop_sectors_from_natures()`), (2) les mots du nom, de l'accroche et de l'indication (« fripe », « tissus » → Mode ;
`shop_sectors_from_text()`). Les univers détectés s'affichent avec leur source (photos, nature des pièces, nom de la boutique) :
« Remplacer mes univers par ceux-ci » ou « Les ajouter » ; rien n'est enregistré avant « Enregistrer les univers ». La phrase de la
boutique comprise par l'IA (`shop_profile()`) remplace ensuite la description d'origine dans **tous** les prompts de l'IA
(`ai_shop_examples()`).

**Classement des pièces.** L'IA choisit l'univers de chaque pièce d'après sa photo, parmi ceux de la boutique, décrits dans le prompt
(`universes_prompt_list()` : « mode = Mode (mode et vêtements…) »), et peut n'en choisir aucun. À défaut, l'univers qui correspond à la
nature détectée (vêtements → Mode) est utilisé. « Reclasser par l'IA » (pièces sans univers valide, ou toutes) rejoue le choix pièce
par pièce, enregistré aussitôt, arrêtable.

## Taille et poids estimés par l'IA

**Taille et poids.** Boutons « ↻ IA » sur Taille et Poids (catalogue, relectures du Studio et de l'ajout rapide),
champs inclus dans « Tout (re)générer » et dans la génération de fiche (Studio, lot, ajout rapide) : taille lue sur
l'étiquette d'un vêtement ou dimensions estimées d'un objet (« env. 22 cm de haut, Ø 11 cm »), poids estimé en grammes
d'après la nature, les matières et la taille apparente (`weight_grams` pour les frais de port ; `weight_text` « env.
900 g » écrit pour la fiche). Ce sont des **estimations** : l'interface le rappelle pour le poids (à peser).

## Nature du produit et sous-catégories, détectées par l'IA

Plus fine que l'**univers** (le grand domaine : Mode, Déco…), la **nature** dit ce que l'objet est : 25 natures et 185
sous-catégories (`product_nature_options()`, colonnes `products.nature` et `products.sous_categorie`, ajoutées
automatiquement aux bases existantes) — Vêtements (hauts, pulls et sweats, vestes, robes…), Chaussures, Accessoires de
mode, Bijoux et montres, Linge de maison, Décoration, Arts de la table et cuisine, Mobilier, Luminaires, Livres, disques et
papeterie, Jeux et jouets, Objets de collection, Musique et électronique vintage, Outils et jardin, Autre. L'IA la
détecte d'après les photos (clés renvoyées validées : une sous-catégorie n'est gardée que si elle appartient à la nature)
**pendant la génération de fiche** (Studio, lot, ajout rapide), dans « Tout (re)générer » et avec le bouton « ↻ IA » du
champ Nature (catalogue, relectures). Les listes Nature et Sous-catégorie sont liées (`assets/nature-select.js`).
Pour les pièces existantes : dans le catalogue, « Détecter la nature (IA) » sur les pièces cochées, ou « Détecter pour les
N pièces sans nature » (une requête par pièce, enregistrée aussitôt : on peut arrêter à tout moment). Côté public, la
fiche affiche « Type : Vêtements › Pulls… » (liens vers la boutique filtrée) et la boutique propose deux filtres, type et
sous-catégorie, avec le nombre de pièces en vente (visibles dès qu'une pièce a une nature).

## Champ « État » : du neuf à restaurer

Chaque fiche a un état choisi dans un barème à 17 nuances (`product_condition_options()`, colonne `products.etat`,
ajoutée automatiquement aux bases existantes), regroupées en **Neuf** (neuf avec étiquette, neuf emballé, neuf avec sa
boîte, neuf sans étiquette), **Comme neuf** (comme neuf, presque neuf, excellent état), **Bon état** (très bon état,
bon état, bon état pour son âge, belle patine), **État correct** (état correct, traces d'usage marquées, petits
défauts signalés) et **À remettre en état** (usé, à restaurer, pour pièces ou décoration), plus « Non précisé ».
Le libellé et sa précision (« Très légères traces d'utilisation, à peine visibles ») s'affichent dans la liste de
la fiche produit. Le champ est dans les formulaires d'ajout et d'édition du catalogue et dans les relectures du
Studio et de l'ajout rapide, avec le bouton « ↻ IA » : l'IA l'estime d'après les photos, prudemment (jamais
« neuf », « emballé » ou « avec étiquette » sans emballage ou étiquette visible, vide si l'état ne se juge pas),
et il est inclus dans « Tout (re)générer » et dans la génération de fiche (Studio, lot, ajout rapide).

## (Re)générer les champs d'une fiche par l'IA

Dans le catalogue, les formulaires d'**ajout** et d'**édition** d'une pièce ont un bouton « ↻ IA » à côté
de chaque champ — nom, univers (catégorie), prix, description, **matières** — et un bouton « Tout
(re)générer » (le bouton plein, en haut du formulaire) qui remplit les cinq champs d'un coup. Les mêmes
boutons sont dans la relecture d'une pièce du Studio (« Mes pièces ») et de l'ajout rapide : tout bloc
portant `data-ai-form` les active (champs repérés par `name` ou `data-ai-input`, pièce par `name="ref"` ou
`data-ai-ref`). L'IA (`admin/product-ai.php`, script `assets/product-ai.js`) regarde les photos de la pièce
(ou la photo choisie dans le formulaire d'ajout, réduite avant envoi) et tient compte des autres champs déjà
saisis pour rester cohérente ; un champ déjà rempli est régénéré en version *différente*. Sans photo, elle
travaille d'après le texte saisi. Les valeurs proposées sont seulement écrites dans le formulaire : on relit,
on corrige, puis on enregistre. Le prix proposé est un seul montant indicatif.

Le champ **Matières** (colonne `materials`, ajoutée automatiquement aux bases existantes) est affiché sur la
fiche produit, rempli par la génération de fiche (Studio, ajout rapide, import par lot) et modifiable à la
relecture.

## Studio : type de pièce, scan, vidéo, ensemble d'objets, shooting d'abord

Application smartphone `studio.php` (parcours « Une pièce » : `quick-add.js` ; « En lot » : `studio.js`).

- **Type de pièce** : Objet (**7 vues : face, profil droit, profil gauche, dos, dessus, dessous, détail** — `studio_object_angles()` ; dans le lot, ces vues sont guidées dans l'ordre, puis les gros plans), **Vêtement** (**recto, verso, étiquette et texture en gros plan** : quatre emplacements, pas d'« autre angle » ; les gros plans ne sont ni détourés ni envoyés pour fabriquer le recto / verso, mais l'IA les lit pour la taille, la marque et la matière)
  ou **Livre / CD / vinyle / DVD** (couverture et dos). Le type est enregistré (`studio_jobs.kind`) : le vêtement reçoit la nature
  « Vêtements » d'office et une consigne à l'IA (nature, sous-catégorie, taille de l'étiquette) ; la nouvelle pièce d'un lot garde le type de la précédente.
- **Plusieurs détails** : dans le parcours d'une pièce, « + Un détail de plus » ajoute autant d'emplacements « Détail 1, 2, 3… » que voulu (10 photos au plus
  par pièce), pour tous les types ; dans le lot, le bouton **Gros plan** fait des photos suivantes des détails (numérotés « Détail n »). Les détails ne sont ni
  détourés ni envoyés pour le recto / verso (`recto_verso_is_closeup()`) ; ils restent des photos de la fiche.
- **Mesurer une pièce** (`assets/measure-tool.js`) : bouton « 📏 Mesurer la pièce » (parcours d'une pièce), « Mesure » (viseur du lot), « 📏 Mesurer » à côté du champ
  « Taille » (relecture). On choisit un **repère de taille connue** posé à côté de la pièce — double décimètre (20 cm), mètre (100 cm), pièce de 2 €, 1 €, 50 c, 20 c ou 10 c
  (diamètres officiels), ou une longueur libre — on le **trace au doigt** (glisser-déposer, avec une loupe sous le doigt) pour étalonner la photo, puis on **trace la hauteur, la largeur,
  la profondeur, le diamètre**… ; les valeurs (modifiables à la main) composent la taille (« H 30 × L 25 × P 12 cm », arrondie au demi-centimètre sous 20 cm). La photo peut être
  une des photos de la pièce ou une nouvelle photo de mesure, gardée avec la pièce mais **masquée** dans la boutique (« Mesure (photo de référence) »). Option « Estimer avec l'IA » :
  Gemini estime les dimensions d'après le repère (action `measure`, écart de l'ordre de 5 à 10 % sur un test) ; le tracé reste plus juste. La taille mesurée est enregistrée
  (`products.size_text`) et **prime sur l'estimation de l'IA** à l'étape « fiche ». **Corrections** : les points A et B de chaque tracé se glissent pour les ajuster, « ↶ Annuler le dernier tracé » (historique de 40 actions), « Retracer » par dimension, « Retracer le repère » ; la fenêtre se ferme par la croix, « Annuler », la touche Échap ou un clic à côté, sans rien enregistrer. La mesure n'est exacte que si l'appareil est parallèle à la pièce (point de vue fixe, support) et le repère au même niveau qu'elle.
- **Nom de l'application et partage** : l'application installée porte le nom de la boutique (« Studio <boutique> » dans le manifeste, nom court = la boutique,
  titre iOS = la boutique ; `studio-manifest.php`, `studio.php`). Une icône « Partager » dans l'en-tête, « Partager l'application » et « Code QR de l'application » dans le menu, un bouton
  sur la page de connexion et, dans l'administration (Prise de vue), « Partager le lien » / « Code QR » ouvrent la fenêtre de partage (`assets/share.js`, forcée en fenêtre complète
  même sur téléphone) : **WhatsApp, SMS, e-mail, Facebook, X, Telegram, LinkedIn, Pinterest, lien à copier, code QR** (PNG, SVG, impression). L'adresse partagée est celle de l'application
  de la boutique (avec son préfixe quand elle est servie sous `/identifiant/`). Une application déjà installée garde son ancien nom jusqu'à sa réinstallation.
- **Photos du shooting masquées, images générées affichées** : une fois une pièce traitée (étape `finish` du Studio / ajout rapide, ou import par lot), `apply_generated_only()`
  masque les **photos du shooting** (face, profils, dos, recto, verso…) et les détourages des autres angles, **s'il existe au moins une image générée visible** (sinon la pièce n'aurait plus de photo).
  Restent visibles : les **gros plans** (étiquette, texture, détail), le **détourage de la photo principale** (fond blanc), l'**ambiance**, le **recto / verso (IA)** et les vidéos ; la couverture des cartes
  ne prend jamais un gros plan (`sync_cover_photo()`). Les photos restent dans la galerie (sources de toute nouvelle génération) : « Masquer / Afficher » photo par photo, **« Montrer seulement les images
  générées »** et **« Tout réafficher »** dans la galerie de la pièce, et réglage du commerce « Masquer par défaut les photos du shooting… » dans Réglages du site (`settings.hide_shooting_photos`, activé par défaut).
  Les images générées gardent leur mention « Vue d'illustration générée par IA » sur la fiche publique.
- **Scan pour les médias** : en choisissant « Livre, CD, vinyle, DVD », barre de scan (code-barres, ISBN, QR : `barcode-scan.js`,
  recherche `admin/barcode-lookup.php`) ; dans le lot, bouton « Scanner le code » devant le viseur. Le code est enregistré sur la pièce
  (`products.barcode`), la **pochette trouvée tient lieu de photo** si le média n'est pas photographié, et à l'étape « fiche » les faits du catalogue
  (titre, auteur, éditeur, pistes, nature, poids…) **priment** sur ce que l'IA devine.
- **Vidéo** : « Filmer la pièce » (appareil du téléphone) dans le parcours d'une pièce ; dans le lot, bouton « Vidéo » qui enregistre depuis le viseur
  (MediaRecorder, 20 s, ≈ 2,5 Mbit/s ; à défaut, l'appareil du téléphone). Envoyée **par morceaux** après les photos (`admin/quick-add-action.php`, action `video`,
  assemblée dans `data/capture-tmp/`) puis ajoutée à la galerie de la pièce (« Vidéo de la pièce »).
- **Ensemble d'objets** : sur une photo, bouton « ✂ Objet » (et, dans le lot, l'icône viseur de la relecture). L'IA repère les objets
  (`studio_detect_objects()`, action `objects`) : on **touche l'objet à vendre** ou on **dessine un cadre**, et la photo est recadrée sur le téléphone
  (`assets/object-picker.js`). Dans le parcours d'une pièce, un bandeau le propose tout seul quand la photo principale montre plusieurs objets.
- **Shooting d'abord, post-traitement ensuite** : en tête de « Une pièce », choix **⚡ Traiter tout de suite** ou **📷 Shooting d'abord**
  (mémorisé sur le téléphone). En mode « shooting d'abord », la pièce est seulement enregistrée (photos, vidéo, type, code) puis le formulaire repart à zéro
  pour la suivante ; un bandeau rappelle les pièces en attente et **les traite d'un coup** (même file que « Mes pièces → Traiter les pièces en attente »).
  L'onglet « En lot » fait la même chose pour une série de pièces.

## Recto / verso automatique (galerie d'une pièce)

Bloc « ↔ Recto / verso automatique » de `admin/gallery.php` : un seul bouton enchaîne, étape par étape (une requête
par étape, donc pas de délai dépassé sur un hébergement mutualisé) :

1. **détourage de chaque angle** (photos de départ, 6 au plus ; fal.ai si configuré, sinon repli Gemini sur fond blanc).
   Chaque détourage est rattaché à sa photo (`product_photos.cutout_of`) : relancer ne détoure pas deux fois la même photo ;
2. **envoi de tous les détourages ensemble** à Gemini (4 au plus, la photo principale d'abord, mis à plat sur fond blanc) ;
3. génération de deux visuels de studio : **Recto (IA)** et **Verso (IA)**, ajoutés à la galerie comme illustrations, avec
   leur version smartphone 9:16. Si aucune photo ne montre le dos, l'IA le déduit prudemment (sans inventer logo ni texte) ;
   avec une seule photo, le verso est donc deviné — l'écran le rappelle.

**Automatique à la création d'une pièce** (Ajout rapide et Studio, `admin/quick-add-action.php`) : dès qu'une pièce a **2 photos
ou plus**, l'enchaînement devient `detoure` (photo principale) → `sheet` (fiche, **avant** la mise en situation pour que la
nature de la pièce décide du décor) → `ambiance` → `angles` (détourage des autres angles, un par appel) → `recto` → `verso`.
Avec une seule photo, ces trois dernières étapes sont sautées (le verso serait deviné). Un angle que l'IA refuse de détourer est
passé : le recto / verso utilise alors sa photo telle quelle ; si l'IA refuse l'ensemble des photos, la génération est redemandée
avec les seuls détourages, puis avec la photo principale seule. L'import par lots (un seul appel par groupe) ne le fait pas.

Code : `includes/recto-verso.php` (logique), `admin/recto-verso-action.php` (étapes `plan`, `cutout`, `generate` du bouton de la galerie).

## Taille du vêtement dans la photo d'ambiance

Une pièce portée est posée sur la toile 3:2 à **55 %** de la hauteur (82 % sinon) et le prompt impose une **échelle réelle** (le
vêtement doit aller à la personne, jamais plus grand que son torse) : avant, un vêtement très grand donnait un mannequin
trop petit pour lui en 3:2 (le 9:16, réglé sur la largeur, était correct). Dans la galerie, « Mise en situation » propose en plus
**Taille de l'objet dans l'image** : automatique, plus petit (−20 %) ou beaucoup plus petit (−40 %) ; le réglage est repris
pour la version 9:16 (`ambiance_subject_fill()`).

## Décor automatique de la photo d'ambiance (selon la nature de l'objet)

Sans consigne du vendeur, la mise en situation n'est plus toujours « un salon français » : `includes/ambiance-scenes.php`
(`ambiance_scene()`) choisit un décor d'après la **nature** de la pièce (`nature` / `sous_categorie` de la fiche, à défaut
les mots du nom et de la description) et **tire au hasard** parmi plusieurs décors adaptés à chaque génération :

- vêtement, chaussure, accessoire, bijou → **porté par une personne** (femme / homme d'après le nom, la description ou
  la sous-catégorie ; sinon selon le public de l'article) dans un jardin, sur un chemin de campagne, dans un bureau, une rue
  de vieille ville, une terrasse de café, un parc ou un marché ; chaussures cadrées sur les pieds, bijoux en portrait rapproché ;
- lingerie, maillots et vêtements d'enfant → jamais portés : posés dans une chambre (ou une chambre d'enfant), sans personne ;
- meubles, décoration, luminaires, linge, arts de la table, livres, disques, outils, alimentaire, informatique… → posés
  dans une pièce ou un lieu qui leur correspond (coin lecture pour un livre, table dressée pour la vaisselle, jardin pour le
  jardinage, atelier pour les outils, etc.), sans personne ;
- nature inconnue → l'IA choisit elle-même un décor adapté à ce qu'elle voit.

La consigne du vendeur reste prioritaire : si elle décrit un décor, une action ou une personne, elle remplace ce décor ;
une simple précision le laisse en place. Le décor tiré est mémorisé avec la version smartphone (9:16) pour que les deux formats
restent cohérents. Dans le lot (import par lots), la fiche est désormais créée par l'IA **avant** la photo d'ambiance, afin
que la nature de la pièce soit connue. L'aperçu du prompt (galerie) liste les décors possibles pour la pièce.

## La consigne du vendeur prime sur le décor par défaut

Les « Mots-clés / précisions » (galerie) et « Indications pour l'IA » (ajout rapide, lot du Studio) sont
transmis à Gemini comme **consigne prioritaire** (`owner_direction_prompt()`), pas comme un simple
complément. Pour une mise en situation, une consigne qui décrit un lieu, une action ou une personne
(« femme dans la rue en mouvement », vêtement porté) **remplace** l'intérieur français par défaut ; une
simple précision (« années 70, éclat au pied ») laisse le décor par défaut. Sans consigne, le prompt est
inchangé. Avant, la consigne n'était qu'une phrase ajoutée à la fin d'un prompt qui imposait « intérieur,
aucune personne » : le modèle l'ignorait. La photo générée **prend le nom de la consigne** (`generated_photo_label()` : « Ambiance — femme dans la rue en
mouvement », raccourci à un mot entier) et son fichier aussi (`product-001-ambiance-femme-dans-la-rue-en-mouvement-….jpg`,
version 9:16 comprise) ; elle reste renommable dans la galerie. Sans consigne, le nom est « Ambiance », « Autre angle »
ou « Objet complété » comme avant. La galerie rappelle le nom donné dans son message de confirmation. La 9:16 d'une mise en situation avec personne ne peut pas être « recadrée » à partir de
l'image finie (le modèle refuse de retoucher une photo réaliste de personne) : elle est alors regénérée
directement depuis la photo de départ avec la même consigne — scène semblable, pas identique.

## Aide à la rédaction du prompt

Sous le champ « Mots-clés / précisions » (galerie) et les « Indications pour l'IA » (ajout rapide, lot du
Studio), `assets/prompt-helper.js` affiche : des **idées cliquables** — décors, situations, lumière et style
(un clic les ajoute au texte, sans doublon) — dont des **cadrages** (plan large, gros plan sur le détail, vue de dessus, plongée, contre-plongée, arrière-plan flou…) ; pour une pièce existante, un bouton « Idées pour cette pièce
(IA) » qui en propose huit d'après sa photo (`suggest`, adaptées à l'objet : un vêtement est proposé porté) —
et le **prompt réellement envoyé à l'IA**, mis à jour à la frappe, avec la consigne surlignée et le cadrage
ajouté automatiquement. L'aperçu est **en français** par défaut (case « Voir le texte exact envoyé (en anglais) » pour basculer) : le
modèle reçoit les prompts anglais de `includes/functions.php`, dont `includes/prompts.php` tient la traduction
fidèle phrase à phrase (`build_*_prompt_fr()`, `generated_framing_prompt_fr()`, `owner_direction_prompt_fr()` —
**à modifier en même temps que les prompts anglais**). Il est construit par le serveur
(`prompt_preview_parts()`, action `preview`), donc fidèle ; pour les indications de fiche il montre la mise en
situation et la rédaction de la fiche. Il est replié par défaut sur téléphone. Un attribut
`data-prompt-helper="<type>"` suffit à activer l'aide sur un champ.

## Prompts enregistrés (« Mots-clés / précisions »)

Sous le champ « Mots-clés / précisions » de la galerie d'une pièce (génération d'une mise en situation,
d'un autre angle, ou d'un objet complété) et sous les « Indications pour l'IA » du parcours « une pièce »
et du lot du Studio, un bouton **Enregistrer ce prompt** garde le texte pour plus tard ; les prompts
enregistrés apparaissent en pastilles dessous : un clic les **ajoute** au texte déjà saisi (séparé par une virgule, sans
doublon, dans la limite de 300 caractères — ce qu'on avait composé n'est plus écrasé), Maj + clic remplace tout le texte,
« × » supprime la pastille.
Chaque type de génération a sa liste (mise en situation, autre angle, compléter, fiche), partagée par
toute l'équipe du commerce (table `saved_prompts`, créée automatiquement ; 300 caractères et 40 prompts
par type au plus ; un prompt déjà enregistré n'est pas dupliqué). Le community manager peut les utiliser
comme la galerie. Code : `includes/prompts.php`, `admin/prompts-action.php`, `assets/saved-prompts.js`
(il suffit d'un attribut `data-saved-prompts="<type>"` sur un champ pour l'activer).

## Studio : l'application smartphone du commerce

`studio.php` (`/<commerce>/studio.php` sous brocs.arrimage.com) est une application
**indépendante de l'administration**, installable sur l'écran d'accueil du téléphone
(manifeste `studio-manifest.php`, icône `studio-icon.php` aux couleurs du commerce,
`studio-sw.php` minimal, sans cache). `admin/quick-add.php` affiche un code QR vers elle.

- **Connexion propre** : identifiant ou e-mail + mot de passe d'un compte *administrateur*
  (un community manager est refusé : le Studio crée des fiches du catalogue). « Rester connecté »
  garde le cookie 30 jours ; les fichiers de session vivent dans `data/sessions/` (jamais servi,
  conservés 31 jours — le répertoire système est purgé en ~24 min). Déconnexion dans le menu.
- **Une pièce** : le parcours de `quick-add.php` (`assets/quick-add.js`, partiel
  `includes/quick-add-flow.php`, partagé avec l'administration).
- **En lot** : viseur en direct (`getUserMedia`, appareil photo arrière) avec obturateur ; un bouton
  « Pièce suivante » sépare les pièces (jusqu'à 8 photos chacune). La relecture permet de choisir la
  photo principale, de couper ou fusionner des pièces, de retirer des photos. Puis « Générer les
  fiches » (envoi + détourage + mise en situation + fiche, pièce après pièce, écran maintenu allumé)
  ou « Envoyer sans traiter ». Sans accès à la caméra, repli sur l'appareil photo du téléphone et la
  galerie. Le lot en cours est gardé dans IndexedDB : page fermée ou téléphone redémarré, il est retrouvé.
- **Mes pièces** : toutes les pièces du Studio (table `studio_jobs`, créée automatiquement) en
  *à traiter* (photos reçues, traitement différé — bouton par pièce ou « tout traiter »), *à relire*
  et *enregistrées*. Une fiche s'ouvre pour correction, publication ou rejet (tant qu'elle n'est pas
  relue ; les photos vont alors à la médiathèque). Une étape IA en échec est signalée sur la fiche.
- Les appels passent par `admin/quick-add-action.php` (actions `create`, `detoure`, `ambiance`, `sheet`,
  `finish`, `jobs`, `get`, `discard`, `save`) ; chaque étape est une requête séparée (limite de durée
  OVH) et reprend où elle s'est arrêtée si le réseau coupe.
- La caméra en direct exige HTTPS (ou localhost) : en ligne, pas de souci.

## Diaporama de la fiche produit : défilement automatique

La galerie de `produit.php` défile seule : 4,5 s par photo, 7 s par vidéo (lue en sourdine, arrêtée quand elle
n'est plus affichée), en boucle, sur les seuls visuels du format de l'écran (3:2 ordinateur, 9:16 smartphone).
Elle s'arrête au survol, quand la galerie sort de l'écran ou que l'onglet est masqué, et **12 s après la dernière
action manuelle** (flèche, miniature, balayage, clavier) avant de reprendre ; elle est coupée pour les visiteurs
qui demandent moins d'animations (`prefers-reduced-motion`). Les diaporamas de l'accueil et de la boutique, et les
vignettes des cartes, défilaient déjà.

## Achat : barre collée sur smartphone et bouton « Commander »

Sur la fiche produit (`produit.php`), un **bouton « Commander »** accompagne « Ajouter au panier », sur ordinateur
comme sur smartphone : il ajoute la pièce puis ouvre directement le panier (`cart-add.php`, le dernier `redirect`
envoyé l'emporte). « Ajouter au panier » reste sur la fiche et sa confirmation (aussi après un « Ajouter » depuis la
boutique) affiche un lien « Commander → » vers le panier (`flash_set(..., $link)`). Sur smartphone (≤ 780 px) une
**barre collée en haut, sous l'entête**, garde sous les yeux le prix (prix barré et promo compris) et les deux
boutons pendant tout le défilement ; sur ordinateur elle est masquée. Pièce vendue : « Vendue » ; prix non fixe :
« Nous contacter », comme avant. Les **cartes de la boutique** (et de l'accueil) ont aussi leur bouton « Commander »,
plein à côté d'« Ajouter » (en contour) ; sur smartphone « Détails » et « Ajouter » forment une ligne et « Commander »
prend toute la largeur dessous.

## Médiathèque : toutes les photos, classées par produit

`admin/media.php` réunit (`get_media_overview()`) les **photos des fiches produit** — originales, visuels IA,
détourages, versions 9:16, et la photo de couverture des fiches créées à la main —, lues en place (rien n'est
dupliqué : elles se gèrent dans la galerie de chaque pièce, lien « Gérer dans la galerie »), et les ressources
propres de la table `media_library` (téléphone, imports, photos conservées après suppression d'une fiche,
ajouts manuels), seules modifiables ici. Classées **par produit** par défaut (une rubrique par fiche, puis
« Ancien article : … » et « Sans produit ») ; « Classer par » propose aussi source, type et mois ; un filtre
« Produit » (tous, sans produit, ou une fiche) s'ajoute à la recherche et au type ; la recherche couvre le nom et
la référence du produit. Pastilles : Visuel IA, Détourage, 9:16, photo ou fiche masquée. Les panneaux « Utiliser
mon téléphone » et « Ajouter une ressource » sont repliés pour voir les photos tout de suite. La vue liste ne
coche que les ressources propres.

## Visuels IA : version ordinateur (3:2) et smartphone (9:16)

Une mise en situation est générée en 3:2 ; sa version 9:16 est **refaite par l'IA comme une vraie
photo verticale de la même scène** (même objet, même décor, même lumière, cadrage portrait : plus de
plafond et de sol, moins de côtés), dans une requête à part (`admin/mobile-variants.php`, lancée par
l'administration et par le Studio). Le visuel fini est envoyé tel quel à Gemini, sans bandes ajoutées :
l'ancienne méthode (3:2 posée sur une toile floutée, à « prolonger ») laissait souvent ces bandes
floutées. Un écart de ratio ≤ 8 % est corrigé par un léger recadrage centré ; au-delà le résultat est
refusé. Trois essais au plus ; sans réussite, **aucune version floutée n'est fabriquée** : le site
sert alors la 3:2. Le repli sans IA ne subsiste que pour l'ombre portée d'un objet détouré (fond uni).
Une 9:16 déjà ratée se refait depuis la galerie de la pièce : « ↻ Refaire la version 9:16 (IA) ».

## Déployer le portail en ligne (ex. brocs.arrimage.com)

Le mode `--portail` du script de déploiement envoie **tous les commerces et
le portail** dans un même dossier OVH. En ligne, le portail exige une
connexion (compte défini dans `.env.deploy.portail`, stocké haché dans
`.secrets/portail.json` sur le serveur) ; sans ce compte il reste fermé.

1. Créer `.env.deploy.portail` (jamais envoyé sur GitHub) :
   ```
   FTP_SERVER=ftp.clusterXXX.hosting.ovh.net
   FTP_USER=…
   FTP_PASS=…
   FTP_PATH_FRONT=/www/brocs/
   URL_FRONT=https://brocs.arrimage.com
   PORTAIL_USER=phil
   PORTAIL_PASSWORD=un-mot-de-passe-long
   ```
2. `./deploy-brocante.sh --portail --with-db` la première fois (envoie aussi
   les bases locales), puis `./deploy-brocante.sh --portail` ensuite (les
   bases du serveur ne sont plus touchées). Une base absente du serveur est
   créée automatiquement à la première visite du commerce.
3. Dans l'espace client OVH : **Hébergement → Multisite → Ajouter un
   domaine** pour `brocs.arrimage.com` uniquement, dossier racine `www/brocs`
   (comme `www/brocante`), SSL activé. Les commerces sont servis sous
   `brocs.arrimage.com/<identifiant>/` : aucun autre domaine à créer.

Le fichier `.htaccess` à la racine interdit l'accès aux bases (`*.db`), aux
dossiers cachés (`.secrets/`, `.tenant`…) et aux dossiers internes (`data/`,
`tenants/`, `includes/`…).

## Partager : réseaux sociaux, lien et code QR

Les pages publiques portent un bouton **Partager** et un bouton **QR code** (`includes/share.php`, `assets/share.js`, `assets/share.css`) : sur chaque **fiche de pièce**
(à côté de l'achat), sur l'**accueil** (à côté des boutons de l'en-tête), sur la **boutique** (le lien partagé garde l'univers et la recherche en cours), en **bas de chaque page**, et dans l'en-tête de la **liste des galeries** et de la **page d'une galerie** ; dans les trois modèles de boutique.
- **Fenêtre de partage** : WhatsApp, Facebook, X, Pinterest (avec la photo), LinkedIn, Telegram, SMS, e-mail, champ « Copier le lien ».
  Sur téléphone, « Partager » ouvre d'abord le **menu de partage du système** (qui propose aussi Instagram, Messages…) ; Instagram n'a pas de lien de partage web, d'où ce menu et le lien à copier.
- **Code QR** de la page, fabriqué dans le navigateur : **téléchargeable en PNG ou SVG** et **imprimable** (étiquette de rayon, vitrine, carte de visite).
- **Aperçu du lien partagé** : les pages émettent les balises Open Graph / Twitter (titre, description, prix et photo de la pièce, adresse canonique), pour que Facebook,
  WhatsApp, etc. affichent une carte soignée. Une page peut poser `$ogImage`, `$ogDescription`, `$ogType`, `$ogUrl` avant d'inclure `includes/header.php`.

## Scanner un code-barres ou un QR pour créer l'article (livres, CD, vinyles, DVD)

Une barre **« Scanner un code-barres / QR »** s'ouvre en tête du formulaire « Ajouter une pièce » (catalogue) et de la relecture de l'ajout rapide / du **Studio**
smartphone ; sur une fiche existante, le champ **Code-barres / ISBN** a un bouton « Fiche » qui complète les champs vides. Trois façons de donner le code :
la **caméra** (https obligatoire, donc en ligne ; BarcodeDetector du navigateur, sinon la bibliothèque html5-qrcode chargée à la demande, ex. iPhone), une **photo**
du code (appareil photo ou fichier) ou la **saisie** (ISBN, EAN, UPC, ou adresse lue dans un QR).

La fiche est cherchée côté serveur (`admin/barcode-lookup.php`, `includes/barcode.php`), sans clé d'API :
- **Livres** (ISBN-10 ou 13, y compris un ASIN Amazon d'un livre) : BnF (catalogue français), Open Library (couverture, poids, sujets, extrait), Google Books pour le résumé s'il répond ;
  titre, auteur, éditeur, année, pages, langue, collection ; nature « Livres, disques et papeterie » et sous-catégorie (livres, BD, jeunesse, cuisine) déduites.
- **CD, vinyles, cassettes, DVD** (EAN/UPC) : MusicBrainz (artiste, titre, label, année, pays, support, **liste des pistes**) et pochette (Cover Art Archive) ; poids estimé selon le support.
- **Code QR** : adresse Discogs (release ou master), ISBN ou ASIN dans une adresse, sinon titre / description / image de la page (Open Graph) ; autres EAN : UPCitemdb (essai gratuit limité).
Le formulaire est pré-rempli **sans écraser** ce qui est déjà saisi (« Remplacer tout » force) ; la pochette peut devenir la photo de la pièce (case cochée par défaut) ;
le code est enregistré avec la pièce (`barcode`), un **doublon** est signalé, et l'ISBN / code-barres s'affiche sur la fiche publique. Prix et état restent à renseigner.
Résultats gardés 30 jours (`data/barcode-cache/`). Les adresses venant d'un QR ne sont ouvertes que si elles mènent à une machine publique (jamais le réseau local).

## Détourage haute précision (Modal)

Quand le service Modal est configuré (voir « Vidéo IA — Wan 2.2 5B sur Modal » ci-dessus) et que rembg local n'existe pas (hébergement
mutualisé), le bouton « Détourage » de la galerie utilise `modal/ltx_video_app.py` (classe `Cutter` : bibliothèque `rembg`,
modèle **BiRefNet** sur 4 processeurs, sans GPU ; poids gardés dans le volume Modal). Sur une photo de sweat imprimé, il garde tout
le lettrage fin d'une manche qu'`isnet-general-use` (le modèle léger de rembg) efface en partie. La première demande après une pause
peut prendre 1 à 2 minutes (démarrage à froid + chargement du modèle ; 20 s environ ensuite, le conteneur reste chaud 5 minutes) : le
détourage est donc suivi par la page comme une vidéo (table `veo_jobs`, modèle `cutout`, actions `cutout_start` / `veo_poll` de
`admin/video-action.php`), une tâche par photo (3 au plus). Les flux synchrones (import par lot, Studio) gardent fal.ai puis le fond
blanc IA, car ils ne peuvent pas attendre un démarrage à froid. Coût estimé : 0,001 $ par détourage (tarif « Détourage », modifiable).
Après toute modification du script : `modal deploy modal/ltx_video_app.py`.

## Plusieurs photos de départ (galerie d'une pièce)

Galerie d'une pièce → « Générer une nouvelle vue » : la photo de départ est un sélecteur de miniatures où l'on peut en cliquer
**plusieurs** (la première choisie est la principale, marquée ★ ; les suivantes sont numérotées). Selon le type :

- **Autre angle, mise en situation, compléter l'objet** : toutes les photos (4 au plus) partent ensemble chez Gemini avec une
  consigne « photos de référence du MÊME objet » (`multi_photo_prompt()`), et l'IA produit UNE image qui tient compte de
  tous les côtés (dos, détails, étiquettes). La photo principale est celle posée sur la toile du format voulu ; le coût ne change pas.
- **Détourage** et **vidéo zoom/travelling** : un résultat PAR photo (3 détourages, 6 vidéos au plus par demande ; un résumé
  « n sur m ajoutés » s'affiche).
- **Vidéo IA** (Veo, Wan) : une vidéo par photo (6 au plus) ; le coût estimé est multiplié par le nombre de photos, et la page suit
  toutes les générations en parallèle.

## Modèles de boutique : de simples variantes de style, et de vraies mises en page

Un modèle (`includes/templates.php`) se choisit à la création de la boutique (étape 3) et se change ensuite dans **Administration → Apparence → Modèle de
mise en page** (avec ou sans la palette et les polices du modèle) ; « Aperçu » parcourt tout le site avec un modèle sans rien enregistrer (`?modele=<clé>`,
l'aperçu dure le temps de la visite, bandeau noir « Quitter l'aperçu » ou `?modele=0`).

- **Brocante, Atelier, Galerie** : mêmes pages, autre palette, autres polices et feuille de style (`assets/templates/<clé>.css`, layout « classic »).
- **Librairie** et **Mode** : une **structure de pages différente** (wireframe propre), pas seulement un autre habillage. Chacun a son dossier
  `views/<layout>/` (`header`, `footer`, `home`, `shop`, `product`, `card` : une vue absente retombe sur la page classique) et sa feuille
  `assets/templates/<clé>.css` (chargée par `<link>`, tout préfixé par `html[data-layout="<layout>"]`). Mêmes données que le modèle classique
  (produits, textes, univers, galerie de la fiche), donc aucun changement de base.

| | Librairie (livres, BD, disques, papeterie) | Mode (vêtements, accessoires, friperie) |
|---|---|---|
| En-tête | bandeau d'info, **recherche large au centre**, barre des rayons | nom centré, navigation à gauche, **flotte sur la grande image** puis se solidifie |
| Accueil | « à la une » (couverture + grand résumé), **rayons à gauche**, **étagères défilantes** de couvertures, mot du libraire | **image plein écran**, bandeau défilant, univers en grandes tuiles asymétriques, nouveautés, pièce en promo en pleine largeur |
| Catalogue | **filtres latéraux avec effectifs** et filtres actifs effaçables, tri, **grille / liste** | **barre collée** (onglets d'univers, recherche, type, tri), grille serrée de grandes images, 2ᵉ image au survol |
| Fiche | couverture portrait, **grand résumé + fiche détaillée**, **encart d'achat collé** | **images empilées** (défilement horizontal sur smartphone), infos collées, **taille en évidence**, volets |

Choix issus de pratiques courantes du e-commerce de livres (recherche en vedette, étagères, filtres persistants avec effectifs, longue description) et de
mode (images grandes, grille serrée avec image au survol, lookbook, achat collé, taille bien visible). Pour ajouter un modèle : une entrée dans
`SHOP_TEMPLATES` avec un `layout`, un dossier `views/<layout>/`, une feuille CSS et, si l'on veut, une maquette dans `shop_template_wireframe()`.
Les pièces étant en exemplaire unique, la fiche « Mode » montre la taille (champ « Taille » de la pièce) plutôt qu'un sélecteur de tailles.

## Vitrine de la plateforme : accueil, annuaire, inscription

Sur le domaine du portail (déploiement « portail »), la racine `/` n'ouvre plus le portail mais la **vitrine publique** de la plateforme
(le portail de l'exploitant reste à `/portail/`) :

- **`/`** (`accueil/index.php`) : présentation, étapes, **formules**, galeries publiées, commerces à la une, questions fréquentes.
- **`/annuaire/`** (`annuaire/index.php`) : tous les commerces (de ce serveur et « d'ailleurs »), recherche et filtre par univers, avec aperçu
  des pièces ; calculé depuis le catalogue de chaque commerce et mis en cache 10 minutes (`data/saas/cache.json`).
- **`/inscription/`** (`inscription/index.php`) : demande de boutique d'un commerçant (nom, adresse, compte, formule, galerie souhaitée). **Rien
  n'est créé tout de suite** : la demande est enregistrée (`data/inscriptions/<id>.json`, mot de passe seulement haché), l'exploitant est prévenu
  par e-mail, et le commerçant reçoit un accusé. Protections : jeton CSRF, champ piège, 5 demandes par heure et par IP, adresse et e-mail uniques,
  adresses réservées (`galerie`, `portail`, `admin`…).
- **Validation** : portail → **« Inscriptions »** (`portail/inscriptions.php`) : « Valider et créer la boutique » crée `tenants/<adresse>/` (base,
  logo, une catégorie « Nos articles » ; le commerçant règle ensuite ses univers), y installe le compte choisi par le commerçant (identifiant = son
  e-mail, mot de passe haché tel que saisi), l'ajoute à la galerie demandée et lui écrit les liens ; « Refuser » le prévient, avec un motif facultatif.
- **Réglages** : portail → **« Offres et annuaire »** (`portail/offres.php`) : nom, slogan, couleur et e-mail de contact de la plateforme (`data/saas.json`),
  jusqu'à 4 formules (nom, prix, avantages ; ⚠ les limites écrites, « jusqu'à 20 pièces », sont du texte : **elles ne sont pas appliquées**), commerces
  visibles dans l'annuaire, commerces d'ailleurs (comme le Petit Chalet, via son `/catalogue.php`).
- **Code** : `includes/saas.php` (réglages, annuaire, demandes, e-mails, mise en page), `assets/saas.css`. Routes : `.htaccess`, `router.php`, `LocalValetDriver.php`.
  Ces pages ne sont pas envoyées dans le déploiement d'une boutique seule.
- **Pas encore** : paiement des formules (abonnement Stripe), application des limites par formule, changement d'adresse par le commerçant, e-mail de
  réinitialisation du mot de passe, inscription sans validation de l'exploitant.

## Création de boutique : formule, paiement, puis génération — sans validation

Le parcours public (`/inscription/`) se fait en **trois étapes, le paiement AVANT la création, et aucune validation de l'exploitant** : le client arrive sur sa boutique.

1. **Ma formule** (`inscription/index.php`) : formule + e-mail (+ conditions). Le formulaire complet ne s'affiche qu'après le paiement.
2. **Paiement** : formule payante et Stripe configuré → Stripe Checkout (abonnement mensuel). Le retour (`inscription/merci.php`) INTERROGE Stripe pour confirmer la
   session payée, marque la demande « paid » et envoie le client à l'étape 3 ; le webhook signé (`inscription/webhook.php`, `checkout.session.completed` et
   `customer.subscription.deleted`) fait de même et envoie par e-mail le lien de création si le client a fermé la page. Formule gratuite, paiement en ligne non configuré
   ou indisponible : on passe directement à l'étape 3 (paiement « manuel » noté sur la demande et dans l'e-mail à l'exploitant).
3. **Ma boutique** (`inscription/creer.php`, accessible seulement par le lien personnel `r` + jeton secret `k`) : nom, adresse, **design** (3 modèles avec aperçu), compte et mot
   de passe (seulement haché), galerie. À la validation du formulaire la boutique est **générée tout de suite** (`saas_generate_shop()` : modèle, compte, galerie, textes de départ
   modifiables, e-mail) et le client est **redirigé vers sa boutique**. L'exploitant reçoit un e-mail « nouvelle boutique créée » (payée / gratuite / paiement à régler).

États d'une demande (`data/inscriptions/<id>.json`) : `draft` (étape 1 faite, 24 h) → `awaiting_payment` (chez Stripe, 24 h) → `paid` (payée, boutique à créer ; ne s'expire jamais) →
`approved` (boutique créée). Le portail → « Inscriptions » (`portail/inscriptions.php`) suit les boutiques créées, les paiements dont la boutique n'est pas encore créée (« Renvoyer le lien de création »,
« Créer la boutique maintenant » si le formulaire est rempli mais la création a échoué) et les paiements en attente. Une adresse ou un e-mail est réservé tant que la demande est active.
**Garde-fous** (puisqu'il n'y a plus de validation humaine) : jeton CSRF, champ piège, 5 demandes par heure et par adresse IP à l'étape 1, adresse et e-mail uniques, et un **plafond de boutiques
gratuites par 24 h** (10 par défaut ; portail → Offres et annuaire ; `free_daily_cap` dans `data/saas.json`, 0 = aucun plafond ; les formules payantes ne sont pas comptées). L'e-mail n'est pas
vérifié : ajouter une confirmation par lien serait la suite logique si des créations abusives apparaissent.

- **Trois modèles de design** (`includes/templates.php`, feuilles `assets/templates/<clé>.css`) : **Brocante** (le design historique : papier chaud, filets fins,
  machine à écrire), **Atelier** (moderne et doux : coins arrondis, ombres, boutons en pilule ; Bricolage Grotesque / DM Sans, teal et ambre) et **Galerie**
  (éditorial et épuré : grands titres, blanc généreux, noir et or ; Playfair Display / Karla). Un modèle = une palette + une paire de polices + une feuille de style
  (sélecteurs préfixés `html`, plus prioritaires que `style.css`) ; la clé `'template'` de `tenants/<slug>/tenant.php` l'enregistre (`brocante` par défaut). Aperçu sur
  n'importe quelle boutique : `?modele=atelier` (rien n'est enregistré). Pour ajouter un modèle : une entrée dans `SHOP_TEMPLATES` et un fichier CSS.
- **Stripe de la plateforme** : clés dans portail → Offres et annuaire → « Paiement des formules » (`.secrets/stripe_plateforme.json`), sans rapport avec les clés Stripe des
  commerces, qui encaissent leurs propres clients. Clés `sk_test_…` pour essayer sans débit (carte `4242 4242 4242 4242`) ; le portail affiche « mode test » ou « mode RÉEL ».
  Point de terminaison du webhook à déclarer chez Stripe : `<domaine>/inscription/webhook.php`. Les essais automatiques utilisent un faux Stripe local (`api_base` du fichier de
  clés, accepté uniquement avec `config.local.php`).
- **Création de boutique partagée** : `includes/tenant-factory.php` (portail, `portail/nouveau.php`, `portail/inscriptions.php` et étape 3).
- **Pas encore** : modèle modifiable après coup depuis l'administration de la boutique, changement de formule, facturation / portail client Stripe, application des limites par formule.

## Activité de la plateforme (portail → Activité)

`/portail/activite.php` suit tout ce qui se passe sur la plateforme (calculs dans `includes/saas-events.php`) :

- **Chiffres clés** sur 7 j / 30 j / 90 j / 12 mois : boutiques en ligne et créées, entonnoir inscriptions → paiements → boutiques, encaissé par la
  plateforme (paiements, renouvellements, remboursements), abonnements actifs et loyer mensuel estimé, résiliations et suppressions, ventes des boutiques.
- **À traiter** : clients qui ont payé sans créer leur boutique, paiements à régler à part, créations en échec, abonnements résiliés dont la boutique est
  toujours en ligne, paiements refusés récents.
- **Courbes** (créations, encaissements, inscriptions par jour), **formules**, **santé du système** (Stripe, webhook, e-mail, disque, plafond gratuit).
- **Boutiques** : formule, état du paiement, pièces en vente, commandes et chiffre d'affaires (30 j et total), dernière commande, boutiques inactives.
- **Derniers paiements** et **journal** filtrable (inscriptions, paiements, boutiques, suppressions et résiliations, réglages, notes), avec recherche,
  notes libres et exports CSV (journal, paiements, boutiques).

Le journal est `data/saas/events.jsonl` (une ligne JSON par événement, ajout seul) : inscriptions, paiements Stripe, créations (par le client, par
l'exploitant, à la main), suppressions (vers `data/corbeille/`), remises à zéro, changements d'accès, réglages du portail. Ce qui date d'avant le journal
est **reconstitué** d'après les demandes et la corbeille (marqué « reconstitué »). Pour voir renouvellements, échecs de paiement et remboursements,
déclarez en plus dans le webhook Stripe : `invoice.paid`, `invoice.payment_failed`, `charge.refunded`.

## Personnaliser la page d'une galerie

Portail → Galeries commerciales → *Gérer* → **Apparence de la page publique** (stockée avec la galerie, `style` dans `data/galeries/<identifiant>.json`). Un aperçu
simplifié se met à jour pendant la saisie ; rien ne change en ligne avant « Enregistrer l'apparence » (« Rétablir l'apparence d'origine » remet tout à zéro).

- **Palette** : couleur principale, fond, texte et couleur de l'en-tête (cases « personnaliser » : décochées, les teintes d'origine sont gardées), palettes toutes prêtes
  (les mêmes que l'apparence des boutiques), mode sombre calculé ou désactivé (« toujours clair »).
- **Bannière et logo** : image de bannière (assombrissement réglable, cadrage haut/centre/bas, hauteur compact/normal/grand, alignement gauche/centré) et logo (PNG
  transparent conseillé), redimensionnés et enregistrés sous `uploads/galeries/` (JPEG, PNG ou WebP, 12 Mo max) ; l'image de bannière sert aussi d'image de partage.
- **Polices** des titres et des textes (catalogue de l'apparence des boutiques).
- **Cartes et grille** : angles (droits / doux / très arrondis), format des photos (4:5, 3:4, carré, 3:2), grille aérée ou serrée, pièces par page (12 à 96), tri par défaut.
- **Textes et blocs** : chiffres sous le titre, appel « Créer ma boutique » (bouton, tuile, bandeau : on peut le couper ou changer son texte), titres de sections,
  phrase d'introduction des commerces, texte de bas de page.

## Tarifs : par défaut et par galerie, mensuel et annuel, formules ou tranches d'articles

`/portail/tarifs.php` règle tous les prix (`includes/saas-pricing.php`, données dans `data/saas/tarifs.json`) :

- **Une grille par défaut** (accueil, inscription sans galerie) et, pour **chaque galerie commerciale**, des **tarifs propres** (case à cocher : décochée, la galerie applique la grille par défaut ; « Partir d'une autre grille » copie une grille existante).
- Chaque grille propose des **formules** (nom, prix **par mois**, prix **par an**, limite d'articles facultative, avantages, mise en avant) et/ou des
  **tranches par nombre d'articles** (jusqu'à N articles, dernière tranche illimitée). Avec les deux, le client choisit d'un clic entre « Formules » et
  « Selon le nombre d'articles ».
- **Facturation annuelle** : à activer par grille. Un prix annuel laissé vide vaut 12 mois moins la **remise annuelle** de la grille (« 2 mois offerts » ou
  « −17 % » est affiché) ; un prix annuel saisi prime. Prix à 0 = gratuit ; prix vide = sur devis (jamais payé en ligne). Stripe facture à l'année (`interval=year`).
- **La galerie détermine le prix** : le client la choisit dès l'étape 1 de l'inscription (`/inscription/?galerie=<identifiant>`, bouton « Rejoindre cette
  galerie » sur la page de la galerie). L'offre choisie est **figée** dans la demande (prix, facturation, limite, galerie) : modifier les tarifs ensuite ne change pas ce qui a été vendu.
- **La limite d'articles est appliquée** : elle est enregistrée dans le `tenant.php` de la boutique (`item_limit`, 0 = sans limite) ; au-delà, l'administration refuse d'ajouter
  un article (ajout manuel, studio photo, import par lot) et invite à changer de tranche. Les pièces vendues ne comptent plus. Le portail → Activité montre « 12 / 20 » et signale les boutiques pleines.
- Le loyer mensuel estimé (portail → Activité) compte une offre annuelle pour 1/12 de son prix.

Les pages publiques des galeries (`/galerie/` et `/galerie/<identifiant>/`) portent un appel « **Créer ma boutique** » : bouton dans l'en-tête (avec le prix de départ de la grille de la galerie), tuile « ＋ Votre boutique ici » parmi les commerces, bandeau « Vous êtes commerçant ? » en bas de page et bouton flottant sur smartphone ; tous mènent à `/inscription/?galerie=<identifiant>`.

Les anciennes formules (`data/saas.json`) sont reprises telles quelles comme grille par défaut tant qu'aucun tarif n'est enregistré.

## Galeries commerciales

Une **galerie** regroupe les pièces de plusieurs commerces (une rue, un village, un groupe d'amis…) sur une page publique commune,
**`/galerie/<identifiant>/`** (liste des galeries publiées : `/galerie/`), avec un annuaire des commerces, la recherche, les filtres
(commerce, univers, prix) et le tri. Chaque commerce garde sa boutique, son administration, son panier et son paiement : la galerie
présente, et chaque pièce renvoie vers sa fiche chez le commerçant (pas de panier commun).

- **Gestion** : portail → **« Galeries commerciales »** (`portail/galeries.php`, réservé à l'exploitant) : créer une galerie (nom,
  identifiant, slogan, couleur), cocher les commerces membres (★ = à la une), ajouter un **commerce d'ailleurs** (autre serveur, comme
  le Petit Chalet : son adresse, testée à l'ajout), publier. Une galerie non publiée s'ouvre par un lien d'aperçu secret (`?apercu=…`).
  Les galeries sont des fichiers `data/galeries/<identifiant>.json` (jamais envoyés par le déploiement).
- **Données** : chaque boutique sert son **catalogue public** en JSON à `/catalogue.php` (infos du commerce, logo, pièces non masquées et
  en stock, prix, photo de couverture, lien de la fiche). La galerie lit directement la base des commerces de ce serveur et le flux
  `/catalogue.php` des commerces d'ailleurs ; le catalogue regroupé est mis en cache 10 minutes (`data/galeries/cache/`, bouton
  « Actualiser » dans le portail). Un commerce injoignable est signalé et la galerie continue sans lui.
- **Code** : `includes/galleries.php` (galeries, catalogue, cache ; ne dépend que de `includes/tenant.php`), `galerie/index.php` (page
  publique), `catalogue.php`, `portail/galeries.php`. Routes : `.htaccess` (`/galerie/…`) et `router.php` en local.
- **Pas encore** : panier commun à plusieurs commerces, abonnements (vente / location d'une boutique seule ou en galerie), inscription
  libre des commerçants, logo et visuel propres à la galerie.

## Clés API partagées (période de test)

Pendant les essais, une même clé (Gemini, fal.ai, SiliconFlow, Modal) peut servir à **tous les commerces d'un même déploiement** qui
n'ont pas la leur : portail → **« Clés API partagées »** (`portail/cles.php`, réservé à l'exploitant). On y colle une clé, ou on
« reprend la clé d'un commerce » (la copie en clé partagée). Elles vivent dans `.secrets/_partage/` (jamais envoyé par le script de
déploiement) ; `secret_path()` (`includes/shared-secrets.php`) lit d'abord la clé du commerce, puis la clé partagée : **une clé
propre reste toujours prioritaire**, et les réglages d'un commerce signalent « Clé partagée (période de test) » quand c'est elle qui sert.
Un commerçant ne peut ni voir ni modifier les clés partagées. Les clés **Stripe ne sont jamais partagées**. Le Petit Chalet, hébergé
dans un autre dossier (`/www/brocante/`), ne voit pas le dossier du portail (`/www/brocs/`) par défaut : pour qu'il lise aussi les clés partagées du portail, `SHARED_SECRETS_FROM=../brocs` dans son `.env.deploy` fait déposer par `deploy-brocante.sh` un fichier `.shared-secrets-from` à sa racine (lecture seule ; ses propres clés restent prioritaires). La page n'écrit que dans le déploiement où elle est ouverte, et « Mise en production » efface donc aussi l'accès de Petit Chalet à ces clés.
**Mise en production** : le bouton « Supprimer toutes les clés partagées » (confirmation en tapant `PRODUCTION`) les efface d'un coup ;
chaque commerce n'utilise plus que ses propres clés (et perd les fonctions d'IA correspondantes s'il n'en a pas).

## Plugins : un seul code, activé boutique par boutique

Un plugin (ou « pack ») ajoute un module aux boutiques sans copier son code chez chacune : il vit dans `packs/<id>/`, part avec
le déploiement (les deux modes), et s'active **boutique par boutique** dans le portail → **Plugins** (`portail/plugins.php`).
Le chargeur est `includes/plugins.php` ; `packs/hello/` est un plugin de démonstration (une page, une petite API, une table de notes).

- **Données isolées** : chaque boutique garde dans sa propre base la table `plugins` (activé, version installée, dernière migration)
  et les tables du plugin, toutes préfixées comme le déclare son manifeste (`hello_…`). Une boutique sans plugin n'a ni table
  `plugins`, ni menu, ni route : rien ne change pour elle. **Désactiver** retire le menu et les pages, sans rien effacer.
- **Manifeste** `packs/<id>/plugin.json` : `id` (= nom du dossier), `name`, `version` (x.y.z), `description`, `table_prefix`,
  `pages` (`id`, `label`, `icon`, `file`, `roles`, et `replaces` : l'entrée du menu de la plateforme dont la page prend la place,
  par exemple `media`), `api` (`id`, `file`, `methods` GET/POST, `roles`) et `admin_assets` (`file`, `roles` : scripts et styles de
  `assets/` chargés sur toutes les pages de l'administration, pour enrichir les pages de la plateforme sans les modifier ; un script
  y reçoit `data-api`, `data-csrf` et `data-role`). Rôles : `admin` (toujours autorisé) et `community_manager`. Un manifeste
  invalide est ignoré (journal d'erreurs PHP).
- **Migrations** : `packs/<id>/migrations/NNN-nom.sql`, appliquées une seule fois chacune, dans l'ordre, dans une transaction.
  Une migration qui nomme une table sans le préfixe du plugin est refusée (le plugin ne peut pas toucher aux tables de la boutique).
- **Adresses** : pages `/admin/plugin.php?p=<id>&v=<page>`, API JSON `/admin/plugin-api.php?p=<id>&r=<route>`. Elles passent par la
  session de l'administration de la boutique (une connexion ne vaut que pour sa boutique), vérifient le rôle déclaré, et l'API exige le
  jeton CSRF (en-tête `X-CSRF-Token`) pour tout ce qui n'est pas GET. Les entrées de menu apparaissent après « Médiathèque »,
  selon le rôle. Rien de `packs/` n'est servi directement, sauf `packs/<id>/assets/` (scripts, styles, images) : voir `packs/.htaccess`,
  `.htaccess`, `router.php` et `LocalValetDriver.php` ; chaque fichier PHP d'un plugin commence par `defined('PLUGIN_RUNTIME') || exit;`.
- **Mises à jour** : on déploie le code, puis dans le portail **« Mettre à jour les N boutiques en retard »** (ou boutique par boutique).
  Entre les deux, le plugin reste **en pause** dans ces boutiques (page « module en cours de mise à jour », API en 503) pour ne jamais
  tourner sur une base pas encore migrée. Avant chaque migration, la base est copiée dans
  `data/backups/<base>-plugin-<id>-<de>-<vers>-<date>.db`. Chaque action est journalisée (portail → Activité).
- **Retour arrière** : redéployer l'ancien code met le plugin en pause (« base plus récente que le code ») ; pour revenir vraiment à
  l'ancienne version, remplacer la base de la boutique par la sauvegarde d'avant la mise à jour (site au repos ; les écritures faites
  depuis sont perdues).

## Pack Communication : la médiathèque d'electroboy80, portée telle quelle (plugin `communication`)

Activé pour une boutique (portail → Plugins), le plugin remplace l'entrée « Médiathèque » du menu par **la médiathèque
d'electroboy80**, avec sa vraie interface (dossiers, recherche, filtres, tris, vues grille et liste, kit, sélection multiple,
doublons, dérivés, recadrages orphelins, variantes, préfixes, import par adresse, liens vidéo, webcam, envoi par morceaux, menu
« Actions » de chaque fichier…). La page classique `admin/media.php` reste accessible. Administrateurs et community managers y ont accès.

**Comment c'est fait (portage fidèle, sans réécriture).**
- `packs/communication/tools/extract-eb80.php` recopie depuis le dossier d'electroboy80 son `admin.html` (dans
  `assets/eb80/admin.html`), ses scripts annexes (`assets/eb80/js/`) et les fichiers serveur de la médiathèque (`eb80/api/` :
  `config.php`, `media.php`, `media-links.php`, `generate-web-images.php`, `settings.php`, et `eb80/reglages-publics.php`), avec
  quelques retouches listées dans le script : il s'arrête si l'une ne trouve plus son motif. `eb80/SOURCE.txt` dit d'où vient la
  copie. **Quand electroboy80 évolue, on relance le script** : `php packs/communication/tools/extract-eb80.php --source=…/electroboy80`.
- **Navigateur** : la page Médiathèque affiche `admin.html` dans un cadre. `assets/eb80/eb80-shim.js` (chargé avant le code
  d'electroboy80) redirige chaque `fetch('/api/…')` vers la route `eb80` du plugin, avec la session de la boutique et le jeton CSRF
  (plus de jeton electroboy80), et lui donne les couleurs et polices de l'administration de la boutique (variables CSS, couleurs
  écrites en dur ramenées aux variables par l'extraction). `eb80-after.js` n'ouvre que les pages portées et masque le menu
  d'electroboy80 ; `eb80-theme.css` règle l'apparence.
- **Serveur** : la route `api/eb80.php` appelle les contrôleurs d'electroboy80 recopiés ; `eb80/compat.php` fixe leurs constantes
  pour la boutique et remplace les fonctions sensibles. La connexion est celle de l'administration de la boutique. L'envoi (direct,
  par morceaux, import par adresse) n'accepte que des images, vidéos (MP4, MOV, WebM) et sons (MP3, M4A, WAV, OGG), d'après leur
  contenu, avec l'extension de leur vrai type. L'import refuse les adresses internes. La suggestion SEO passe par Gemini, dans le
  quota d'IA de la boutique. Le téléchargement YouTube, la fusion et l'extraction audio (yt-dlp, ffmpeg) sont indisponibles. Un
  chemin non encore porté répond vide (lecture) ou « pas encore disponible ».
- **Isolation** : chaque boutique a son dossier `uploads/<boutique>/` et sa propre base d'electroboy80, `data/com/<boutique>.db`
  (jamais servie ni déployée, séparée de la base de la boutique). Les photos des fiches produit apparaissent en plus, en lecture
  seule : les retirer depuis la médiathèque ne touche pas leur fichier (elles se gèrent dans la galerie de chaque pièce).
- **Pages de la plateforme** : les fenêtres « Choisir dans la médiathèque » du hero, du diaporama, des bandeaux et des réglages
  gardent leur bouton « Toute la médiathèque… », et la galerie d'une fiche « Ajouter depuis la médiathèque… » (`assets/picker.js`).
  Ils voient aussi les fichiers envoyés par la médiathèque portée, inscrits dans `media_library` au moment du choix.
- Aucun fichier de `uploads/` n'est jamais exécuté comme script (`.htaccess`, `router.php`, `LocalValetDriver.php`).

## Vidéos de la galerie d'une pièce

Galerie d'une pièce → « Générer une nouvelle vue » → deux types de vidéo, sans ffmpeg obligatoire :

- **Petite vidéo (zoom, travelling)** — gratuite, 3 s, carrée (900 px). Si le serveur a ffmpeg
  (`FFMPEG_BIN`), il la fabrique (`run_ken_burns_video`) ; sinon (OVH mutualisé) elle est fabriquée dans
  le navigateur (`assets/video-gen.js` : canvas + MediaRecorder, MP4 ou WebM selon le navigateur), puis envoyée à
  `admin/video-action.php` (action `upload`).
- **Vidéo IA (Veo)** — Google Veo 3.1 via l'API Gemini (même clé que les images ; **compte avec facturation
  obligatoire**, pas de quota gratuit). Modèles Lite / Fast / Standard, 4, 6 ou 8 s, 720p, 16:9 ou 9:16, mouvement de
  caméra au choix + « Mots-clés / précisions » (prompts enregistrables). La génération est asynchrone
  (11 s à 6 min) : `veo_start` la lance (`predictLongRunning`, image en `bytesBase64Encoded`), la page interroge
  `veo_poll` toutes les 10 s (table `veo_jobs`, reprise après rechargement), puis la vidéo est téléchargée dans
  `uploads/` (Google ne la garde que 2 jours) et ajoutée à la galerie. Chaque requête reste courte (limite de 60 s).
  Le coût (tarif par seconde × durée, modifiable dans « Consommation IA ») est consigné à la fin, seulement si la
  vidéo aboutit. Veo produit une piste audio : les lecteurs du site lisent les vidéos en sourdine.
  Code : `includes/veo.php`.
- **Vidéo IA — Wan 2.2 (SiliconFlow)** — alternative économique à Veo (≈ 0,29 $ par vidéo, durée fixée par le
  service ; 1 $ de crédit offert aux nouveaux comptes d'après leur page de tarifs). Clé : Administration → Réglages du
  site → « SiliconFlow » (fichier `.secrets/<commerce>/siliconflow.key`, bouton « Retirer la clé » inclus) ; sans clé,
  l'option est grisée. L'API vidéo n'est pas au format OpenAI : `POST /v1/video/submit` (image en data URI, 1280×720
  ou 720×1280) puis `POST /v1/video/status` (`InQueue`, `InProgress`, `Succeed`, `Failed`) ; le lien de la vidéo
  n'est valable qu'une heure, elle est donc téléchargée tout de suite. Même suivi (`veo_jobs`) et même coût consigné
  que Veo. Code : `includes/siliconflow-video.php`.
- **Vidéo IA — Wan 2.2 5B sur Modal** — l'option dans le crédit gratuit (30 $ par mois du plan Starter de Modal, facturation à la
  seconde de GPU). Elle a remplacé **LTX-Video**, jugé mauvais après comparaison sur une photo de sweat imprimé (même consigne, mêmes
  photos) : LTX déformait l'objet ou le remplaçait par autre chose dès que le mouvement était marqué ; **Wan 2.2 TI2V-5B** garde
  forme, couleurs et lettrage intacts avec un vrai rapprochement de caméra, en ~22 s de GPU sur un H100 (≈ 0,03 $, ≈ 0,07 $ avec
  le chargement du modèle après une pause ; estimé à 0,07 $ dans « Consommation IA »). Wan 2.2 I2V-A14B (14 milliards de paramètres)
  est un peu plus fidèle mais 10 fois plus lent (~4 min) : non retenu. Le service est le script `modal/ltx_video_app.py` (nom
  historique, conservé pour ne pas changer l'adresse déjà saisie ; points d'entrée `/health`, `/submit`, `/status/<id>`,
  `/video/<id>`, `/cutout`, `/png/<id>` protégés par un jeton), à déployer une fois sur le compte Modal du commerçant :
  `pip install modal` (dans un `python3 -m venv`), `modal setup`, `modal secret create boutique-video-token AUTH_TOKEN=<jeton>`,
  `modal run modal/video_models_test.py::download --which 5b` (télécharge les ~25 Go de poids sur processeur, peu coûteux),
  `modal deploy modal/ltx_video_app.py`. L'adresse affichée et le jeton se saisissent dans Administration → Réglages du site →
  « Modal » (fichier `.secrets/<commerce>/modal-video.json` ; connexion testée à l'enregistrement ; l'adresse doit finir par
  `.modal.run`). Sans configuration, l'option est grisée. Le dossier `modal/` n'est pas envoyé par le script de déploiement.
  **Point décisif : ne jamais envoyer à Wan une photo posée sur une toile aux bandes floues** (le modèle les prend pour un vrai
  décor et « recule » dans une pièce inventée) : la vidéo se fait au **format naturel de la photo** (surface ≈ 480 × 832, multiples de
  32 ; 81 images à 24 i/s ≈ 3,4 s) ; choisir 16:9 ou 9:16 recadre la photo. Prompt : mouvement de caméra net mais lisse + « le produit
  reste net, stable et inchangé » (`build_wan_prompt()`) ; éviter « brise », « tissu qui ondule » (des mains et des pieds apparaissent).
  Le banc d'essai `modal/video_models_test.py` (`modal run … ::test --models-to-run 5b,14b,5b-prompts`) sert à comparer des modèles
  et des consignes avant tout changement. Code PHP : `includes/modal-video.php`. Qualité inférieure à Veo.

## Photos et vidéos depuis un téléphone proche

Administration → **Médiathèque** → « Utiliser mon téléphone » : l'ordinateur affiche un code
QR ; le téléphone qui le scanne ouvre `capture.php` (sans connexion) avec trois boutons —
prendre une photo, filmer, choisir dans la galerie. Chaque fichier arrive dans la médiathèque
(source « Téléphone (code QR) ») et s'affiche en direct sur l'ordinateur (suivi par requêtes
toutes les 2 s, `admin/capture-session.php`).

- Le lien porte un jeton aléatoire de 128 bits, stocké **haché** (tables `capture_sessions` /
  `capture_uploads`, créées automatiquement) : il n'autorise que l'envoi vers la médiathèque
  de CE commerce, expire après 30 minutes d'inactivité, se ferme avec « Terminer » et accepte
  au plus 60 fichiers. Il n'existe pas de lecture possible avec ce lien.
- Photos réduites sur le téléphone (1600 px) puis à 1400 px par le serveur. Vidéos : 30 s
  maximum (vérifié sur le téléphone), 200 Mo au plus. OVH coupe la connexion dès ~16 Mo par requête
  (mesuré, sans erreur lisible) : le téléphone découpe donc chaque vidéo en morceaux de 5 Mo
  (`CAPTURE_CHUNK_BYTES`, moins si PHP accepte moins), envoyés l'un après l'autre (3 essais par
  morceau) et recollés par `capture-upload.php` dans `data/capture-tmp/` (jamais servi ; les
  assemblages abandonnés sont purgés après 24 h). Un morceau rejoué n'est pas dupliqué. Les photos
  restent en un seul envoi. Formats mp4, mov, webm, copiés tels quels. Le contenu
  réel des fichiers est contrôlé, pas seulement l'extension.
- En local (`.test`, `localhost`), le téléphone ne peut pas joindre l'ordinateur : la page le
  signale. Pour un essai réel en local, ouvrir l'administration par l'adresse IP du Mac
  (`php -S 0.0.0.0:8000 router.php`, même Wi-Fi).
- Le community manager peut l'utiliser (page `capture-session.php` ouverte à son rôle).
- Générateur de code QR : `assets/vendor/qrcode-generator.js` (Kazuhiko Arase, licence MIT).

## Comptes : équipe, clients, prospects

Administration → **Comptes** (`admin/accounts.php`, réservée aux administrateurs). Table
`accounts` dans la base de chaque commerce (créée automatiquement à l'ouverture d'une
base ancienne, voir `includes/accounts.php`), une fiche par adresse e-mail.

| Rôle               | Connexion à l'administration | Accès                                                                 |
|--------------------|------------------------------|-----------------------------------------------------------------------|
| Administrateur     | oui                          | tout                                                                  |
| Community manager  | oui                          | diaporamas, bandeaux, médiathèque, galerie photo ; rien d'autre       |
| Client             | non                          | fiche de contact (commandes rapprochées par e-mail)                   |
| Prospect           | non                          | fiche de contact                                                      |

- Connexion par identifiant **ou** e-mail (`/admin/login.php`). Le compte principal du
  commerce (`admin_user` / `admin_password` du `tenant.php`) reste valable et n'est pas
  dans la liste : on ne peut donc pas se verrouiller dehors ; sans compte principal, le
  dernier administrateur de la liste ne peut être ni supprimé, ni désactivé, ni rétrogradé.
- Les droits se contrôlent à chaque requête, par page (`admin_role_can_access()`) : une
  nouvelle page d'administration est **réservée aux administrateurs** tant qu'elle n'est
  pas ajoutée à `ACCOUNT_COMMUNITY_MANAGER_PAGES`. Désactiver un compte coupe sa session
  immédiatement. Mots de passe hachés, formulaires protégés par jeton CSRF.
- **Mon profil** (`admin/profile.php`, menu du bas, tous les comptes de l'équipe) : chacun
  modifie son nom, son téléphone, son e-mail, son identifiant et son mot de passe. E-mail,
  identifiant et mot de passe exigent le **mot de passe actuel**. Le compte modifié est
  toujours celui de la session (jamais un identifiant du formulaire) ; le rôle, l'état et les
  notes restent réservés aux administrateurs. Le compte principal du `tenant.php` n'est pas
  modifiable ici (portail : « Changer l'accès admin »).
- Création automatique : une commande payée crée un client, une inscription à la
  newsletter un prospect (un prospect qui commande devient client).
  « Importer les clients depuis les commandes » rattrape les commandes déjà passées ;
  « Exporter les contacts (CSV) » ne contient jamais les comptes de l'équipe.

## Connexion par les réseaux sociaux (Google, Facebook, Microsoft, Apple)

Un bouton « Continuer avec… » apparaît à trois endroits, dès qu'au moins un fournisseur est actif (portail → **Connexion sociale**) :

- **Création de boutique** (`/inscription/`) : l'e-mail est repris du compte, **aucun mot de passe à créer** (facultatif à l'étape 3) ;
- **Administration de chaque boutique** (`/admin/login.php`) : entre un compte de l'équipe ou le compte principal dont l'e-mail correspond à une
  adresse **vérifiée** par le fournisseur (un compte de l'équipe se crée comme d'habitude dans Administration → Comptes) ;
- **Compte client** (`/compte.php`, lien « Mon compte » du menu) : suivi des commandes payées avec la même adresse ; l'e-mail est
  prérempli au paiement. Le visiteur devient un contact de la boutique (Comptes → source « Connexion sociale »).

**Un seul jeu de clés pour toute la plateforme.** Un fournisseur n'accepte que des adresses de retour déclarées à l'avance : le retour se fait
donc sur UNE adresse centrale, `<domaine du portail>/oauth/callback.php` (affichée dans le portail), qui renvoie la personne sur sa boutique
(`oauth/finish.php`) avec un justificatif signé (HMAC, 2 minutes, destiné à cette boutique, lié à sa session, usage unique). Domaines de
retour acceptés : le domaine central et ses sous-domaines, les `site_url` des commerces du serveur, plus ceux ajoutés dans le portail.

Pour chaque fournisseur : créer une application (le portail donne le lien de la console et la marche à suivre), y déclarer l'adresse de retour,
puis coller l'ID client et le secret dans le portail. **Google** : la plus simple. **Facebook** : app en mode Live + politique de confidentialité
en ligne. **Microsoft** : comptes organisationnels ET personnels (seuls les comptes personnels ont une adresse considérée comme vérifiée ;
les autres peuvent s'inscrire mais pas ouvrir une administration ni un compte client). **Apple** : Services ID + clé `.p8`, HTTPS obligatoire
(le secret client est un JWT ES256 calculé à partir de la clé).

**Réglages et clés propres à chaque boutique** (Administration → **Connexion sociale**, réservé aux administrateurs) : le commerçant choisit où
proposer la connexion (administration, comptes clients), coupe un fournisseur, et peut enregistrer **ses propres clés** (sa propre application
Google, Facebook…), prioritaires sur celles de la plateforme. Avec ses clés, le retour se fait directement sur SA propre adresse
(`<boutique>/oauth/callback.php`, affichée dans la page : à déclarer chez le fournisseur ; HTTPS exigé hors `localhost`), sans passer par le domaine
central, et le justificatif est signé avec la clé de signature de la boutique (`.secrets/<boutique>/oauth.json`, jamais déployé). L'inscription
utilise toujours les clés de la plateforme. Un justificatif ne vaut qu'avec la clé qui l'a signé (plateforme ou boutique, indiqué dans le justificatif).
Des clés propres erronées ne bloquent personne : la connexion par identifiant et mot de passe reste toujours possible.

Clés de la plateforme dans `.secrets/oauth_plateforme.json` (jamais déployé, jamais affiché) ; un commerce hébergé ailleurs les lit via `.shared-secrets-from`
(ajoutez son domaine dans « Autres domaines autorisés »). En local, un simulateur de fournisseur peut être désigné dans « Simulateur » (champ
visible seulement si `config.local.php` existe) ; Google, Facebook et Apple refusent les adresses `.test` : pour un vrai essai en local,
utiliser `php -S localhost:8000 router.php` (Google accepte `http://localhost`).

## Connexion à l'administration

`/admin/` demande un **identifiant** et un **mot de passe** :

- le compte du commerce : `admin_user` / `admin_password` dans son `tenant.php`
  (identifiant `admin` par défaut ; le portail enregistre le mot de passe haché —
  pour le changer, remplacer la valeur par le nouveau mot de passe en clair) ;
- le compte de la configuration : `ADMIN_USER` / `ADMIN_PASSWORD` (variables
  d'environnement, ou `config.local.php` sur le Mac, identifiant `admin` par
  défaut), valable pour tous les commerces.

Une connexion ne vaut que pour le commerce où elle a été faite.

## Créer un nouveau commerce en ligne de commande

```bash
scripts/new-tenant.sh boulangerie-martin "Boulangerie Martin"
# puis adapter tenants/boulangerie-martin/tenant.php et seed-data.json,
# et remplacer assets/tenants/boulangerie-martin/logo.png
```

## Essayer un commerce (session cloud ou Mac)

```bash
ADMIN_PASSWORD=essai scripts/cloud-setup.sh exemple-librairie
# → http://127.0.0.1:8000  (administration : /admin/, mot de passe « essai »)
```

Le script crée la base du commerce si elle n'existe pas (`schema.sql` +
`seed-data.json`) puis lance le serveur PHP intégré. Une base existante n'est
jamais écrasée. Pour recharger les données de démonstration :
`TENANT=exemple-librairie php seed.php`.

## Déployer sur OVH

```bash
./deploy-brocante.sh                                  # Petit Chalet (.env.deploy)
./deploy-brocante.sh --tenant exemple-librairie       # autre commerce (.env.deploy.exemple-librairie)
./deploy-brocante.sh --tenant exemple-librairie --with-db   # premier déploiement : envoie aussi la base
```

Chaque commerce a son propre fichier d'identifiants FTP `.env.deploy.<slug>`
(même format que `.env.deploy`). Seule la configuration du commerce déployé est
envoyée sur son serveur, avec un fichier `.tenant` qui le désigne. Le dossier
`uploads/` n'est envoyé que si `deploy_uploads` vaut `true` dans son `tenant.php`
(c'est le cas du Petit Chalet), pour ne pas copier ses photos chez les autres.

Le script **ne commite et ne pousse plus rien** : le dossier de travail contient aussi des essais locaux (`uploads/`, commerce
`kllklmk`, chantiers en cours) qui ne doivent jamais partir dans un commit. Il signale seulement les fichiers suivis modifiés
et non commités qui partent en ligne ; le commit du seul code se fait à la main. Il n'envoie jamais `plugin/` (ancien
prototype), `admin/plugin-mediatheque.php`, `.plugin_enabled` ni les fichiers de travail `_*.md` (que le `.htaccess` refuse
aussi de servir).

## Envoyer des pièces du Mac vers la préprod

Administration → **Envoyer en préprod** : les pièces créées en local partent
vers la préprod avec leurs photos, visuels (3:2 et 9:16) et vidéos, sans FTP
et sans écraser la base distante (commandes, réglages et autres pièces restent
intacts).

1. Déployer une fois cette version sur la préprod (`./deploy-brocante.sh`).
2. **Sur la préprod** : Administration → Envoyer en préprod → section 3,
   « Générer une clé », puis la copier (elle n'est affichée qu'une fois).
3. **Sur le Mac** : même page, section 1, saisir l'adresse de la préprod et
   coller la clé.
4. Cocher les pièces, puis « Envoyer la sélection ».

Une pièce présente là-bas sous le même numéro est mise à jour. Si ce numéro y
désigne une autre pièce, elle est envoyée sous un nouveau numéro, retenu pour
les envois suivants. Les fichiers déjà présents ne sont pas renvoyés ; un
fichier modifié est rangé à côté de l'ancien, jamais par-dessus. La clé n'est
stockée que sous forme d'empreinte dans `.secrets/` ; « Désactiver la
réception » coupe tout envoi.

