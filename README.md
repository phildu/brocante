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
du `tenant.php` (`logo`, `logo_macaron`) s'appliquent. Chaque déclinaison peut
aussi être reprise d'un visuel de la **médiathèque** (avatar du vendeur, ancien
logo, photo) : le fichier est copié dans `uploads/logo-…`. Un clic sur un logo
l'affiche en grand, et « Agrandir ×2 » double sa résolution (jusqu'à 4 096 px,
sans IA ni crédit) pour l'impression.
L'apparence est enregistrée dans la base du commerce (table `settings`) et
prime sur les couleurs et polices de son `tenant.php` ; « Revenir à
l'apparence d'origine » l'efface.

## Présence physique ou en ligne

À la création d'un commerce, choisissez **Physique**, **En ligne** ou **Physique
et en ligne**. Le choix se modifie ensuite dans Administration → **Boutique
physique / en ligne** et s'affiche dans l'en-tête public. Les galeries ont le
même réglage à leur création et dans leur formulaire de présentation ; la
mention apparaît sur leur page publique et dans le portail. Les anciennes
fiches conservent par défaut la valeur **En ligne**. C'est une indication
descriptive qui ne change pas les ventes, les livraisons ni les horaires ;
« virtuel » n'est pas séparé d'« en ligne » dans cette version.

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
- **Barème des états** (Administration → **Barème des états**) : chaque boutique règle le pourcentage associé à chacun des états, indépendamment des autres boutiques. Dans le catalogue et dans la relecture de la création photo, avec un état et un prix du neuf renseignés, une suggestion est calculée (prix du neuf × pourcentage) ; le bouton **« Utiliser ce prix »** la reporte dans le prix affiché, sans jamais le remplacer automatiquement. Les valeurs initiales sont indicatives et modifiables.

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

## Studio photo guidé (administration, Articles à vendre)

Parcours guidé de l'administration (`admin/shooting-assistant.php`, `assets/shooting-assistant.js`), distinct de l'application smartphone `studio.php`.
Les deux onglets de prise de vue s'appellent désormais **Articles à vendre** (par défaut) et **Reportage boutique** (`includes/shooting-tabs.php`) ;
le second remplace l'ancien « Boutique / stand ».

- **Première utilisation** : deux questions — voix ou non, article par article ou en lot. Les réponses sont mémorisées sur l'appareil ; le texte
  d'explication de l'assistant se replie après la mise en route, et celui de la photo d'ensemble après la première analyse (`<details>` + `localStorage`).
- **Type de pièce** : liste complète (objet, vêtement, chaussures, accessoire, bijou, média, vaisselle, décoration, mobilier, luminaire, textile,
  collection, électronique, jeu, beauté) plus **Autre** et **Automatique**. L'IA reconnaît la nature sur la première photo, puis le vendeur **valide ou change**
  le type ; le programme de vues et la catégorie du produit suivent ce choix.
- **Appareil photo** : la caméra s'ouvre automatiquement après validation du type (aperçu live, déclencheur, fermeture). Si elle est refusée ou indisponible,
  l'écran propose l'appareil natif ou la galerie. Le contrôle local (netteté, lumière, contraste) **parle** en cas de défaut et propose **Corriger avec l'IA**,
  la correction locale de lumière, ou la poursuite du shooting.
- **Arrêt en cours de route** : « ⏹ Arrêter la séance » (programme) et « ⏹ Arrêter le shooting » (vues) conservent les photos déjà prises et passent au récapitulatif.
- **Mode lot** : `source=batch` enregistre chaque article sans lancer la chaîne IA ; le lot s'affiche dans le récapitulatif et se traite ensuite depuis
  `/admin/studio.php` (`studio_jobs`, statut `queued`).
- **Vidéo de l'objet** : dans le récapitulatif, quatre modes — rotation sur lui-même ou tour de l'objet, en continu (MediaRecorder, 20 s) ou image par image
  (images assemblées sur canvas). La vidéo est envoyée par morceaux avec les photos (`action=video`) et rattachée à la pièce.

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
Ouvert depuis son icône, un lien ou le code QR, le Studio propose sur téléphone la feuille
commune `assets/pwa-save.js` (étapes adaptées à Safari, Chrome, Samsung, Firefox…) et le
bouton « Installer l'application » du menu la rouvre ; la page shooting (`admin/shop-shooting.php`)
a son propre bouton « 📲 Installer l'appli sur ce téléphone ».

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
- **`/inscription/`** (`inscription/index.php`) : création d'une boutique (formule, galerie souhaitée). L'e-mail n'est pas demandé à cette étape. La demande est enregistrée
  (`data/inscriptions/<id>.json`, mot de passe seulement haché) ; après paiement éventuel, le commerçant crée sa boutique lui-même. Protections :
  jeton CSRF, champ piège, 5 demandes par heure et par IP, adresse de site unique. Une adresse e-mail peut être réutilisée pour plusieurs boutiques ;
  leurs accès d'administration sont indépendants,
  adresses réservées (`galerie`, `portail`, `admin`…).
- **Demandes historiques** : portail → **« Inscriptions »** (`portail/inscriptions.php`) permet encore de traiter les anciennes demandes en attente.
- **Réglages** : portail → **« Offres et annuaire »** (`portail/offres.php`) : nom, slogan, couleur et e-mail de contact de la plateforme (`data/saas.json`),
  jusqu'à 4 formules (nom, prix, avantages ; ⚠ les limites écrites, « jusqu'à 20 pièces », sont du texte : **elles ne sont pas appliquées**), commerces
  visibles dans l'annuaire, commerces d'ailleurs (comme le Petit Chalet, via son `/catalogue.php`).
- **Code** : `includes/saas.php` (réglages, annuaire, demandes, e-mails, mise en page), `assets/saas.css`. Routes : `.htaccess`, `router.php`, `LocalValetDriver.php`.
  Ces pages ne sont pas envoyées dans le déploiement d'une boutique seule.
- **Pas encore** : paiement des formules (abonnement Stripe), application des limites par formule, changement d'adresse par le commerçant, e-mail de
  réinitialisation du mot de passe, inscription sans validation de l'exploitant.

## Création de boutique : formule, paiement, puis génération — sans validation

Le parcours public (`/inscription/`) se fait en **trois étapes, le paiement AVANT la création, et aucune validation de l'exploitant** : le client arrive sur sa boutique.

1. **Ma formule** (`inscription/index.php`) : choix entre une boutique avec vente en ligne et un site vitrine sans prix, panier ni paiement, puis formule.
   Aucun e-mail ni compte social n'est demandé à cette étape. Les prix annuels remisés sont affichés en premier, avec l'équivalent mensuel et l'économie réalisée sur douze mois.
   Les tarifs sont distincts pour les deux modes et peuvent aussi varier par galerie ; les vitrines proposent par défaut les offres gratuites, 10 € et 30 € par mois.
   Les avantages des formules vitrine sont configurables depuis le portail, sans liste prédéfinie. Le formulaire affiche aussi le catalogue des plugins avec les tarifs configurés
   (achat après création), et permet de demander un devis pour un design personnalisé, l’accompagnement au setup, une formation à distance ou en présentiel, la hotline ou un chatbot.
   Ces demandes sont conservées avec l’inscription et signalées à l’exploitant à la création ; elles ne s’ajoutent pas au paiement de la formule. Le formulaire complet ne s'affiche qu'après le paiement.
2. **Coordonnées et paiement** (`inscription/paiement.php`) : pour une formule payante avec Stripe configuré, le client saisit un nom de boutique, peut décrire son activité et sa zone, puis demander à OVH de vérifier les extensions `.fr`, `.com`, `.net` et `.shop`. Des variantes proches sont testées si le nom exact est déjà pris ; Gemini peut proposer d’autres noms adaptés au métier et à l’emplacement. Le client peut garder l’adresse incluse sous le domaine de la plateforme ou présélectionner un domaine pour l’acheter après la création. Cette présélection n’est pas une réservation : disponibilité, devis et achat seront confirmés ultérieurement avec OVH et un paiement Stripe dédié. L’adresse sans domaine personnalisé est de la forme `brocs.arrimage.com/nom-de-la-boutique` ; une migration ultérieure peut demander des redirections et affecter le référencement. Il saisit ensuite son e-mail ou continue avec Google. L'adresse e-mail vérifiée par Google est liée à la demande et sert à la connexion ultérieure à l'administration de la boutique ; le consentement est enregistré à cette étape. Le client est ensuite redirigé vers Stripe Checkout pour le paiement (abonnement mensuel ou annuel). Le retour (`inscription/merci.php`) INTERROGE Stripe pour confirmer la
   session payée, enregistre l'e-mail fourni par Stripe, marque la demande « paid » et envoie le client à l'étape 3 ; le webhook signé (`inscription/webhook.php`, `checkout.session.completed` et
   `customer.subscription.deleted`) fait de même et envoie par e-mail le lien de création si le client a fermé la page. Formule gratuite, paiement en ligne non configuré
   ou indisponible : on passe directement à l'étape 3 (paiement « manuel » noté sur la demande et dans l'e-mail à l'exploitant).
3. **Ma boutique** (`inscription/creer.php`, accessible seulement par le lien personnel `r` + jeton secret `k`) : pour une offre gratuite ou un paiement manuel, e-mail et consentement ; puis nom, adresse, compte et mot de passe (seulement haché).
   À la validation du formulaire la boutique est **générée tout de suite** (`saas_generate_shop()` : modèle Brocante par défaut, mode avec vente ou vitrine, compte et textes
   de départ modifiables). La galerie sélectionnée pour le tarif ne reçoit pas automatiquement la boutique. Depuis l'étape d'identité (`inscription/identite.php`), le commerçant
   peut tester en privé l'affichage de sa boutique dans cette galerie ; l'aperçu n'enregistre aucune adhésion et ne modifie pas le cache public. Il décide ensuite explicitement
   de rejoindre ou non la galerie, et peut retirer sa boutique plus tard depuis le même lien. Le design et les options d'identité sont également proposés à cette étape.
   L'exploitant reçoit un e-mail « nouvelle boutique créée » (payée / gratuite / paiement à régler).

États d'une demande (`data/inscriptions/<id>.json`) : `draft` (étape 1 faite, 24 h) → `awaiting_payment` (chez Stripe, 24 h) → `paid` (payée, boutique à créer ; ne s'expire jamais) →
`approved` (boutique créée). Le portail → « Inscriptions » (`portail/inscriptions.php`) suit les boutiques créées, les paiements dont la boutique n'est pas encore créée (« Renvoyer le lien de création »,
« Créer la boutique maintenant » si le formulaire est rempli mais la création a échoué) et les paiements en attente. Une adresse de boutique est réservée tant que la demande est active ; un e-mail peut servir à plusieurs boutiques.
**Garde-fous** (puisqu'il n'y a plus de validation humaine) : jeton CSRF, champ piège, 5 demandes par heure et par adresse IP à l'étape 1, adresse de boutique unique, et un **plafond de boutiques
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
- **Tarifs** : portail → **Tarifs** (`portail/tarifs.php`) permet de modifier séparément les grilles « Avec vente » et « Vitrine », par défaut et par galerie.
  Les vitrines n'affichent pas de prix de produits et leurs routes de panier/paiement sont désactivées ; les boutiques existantes restent en mode vente par défaut.
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

Deux autres niveaux de prix viennent du **type de galerie** (voir « Types de galerie ») : les **offres du type** (abonnement de la galerie : mensuel, annuel, membres, essai) et le **prix indicatif de chaque lieu de vente** du type, qui préremplit le tarif de la zone dans le plan d'implantation.

Les anciennes formules (`data/saas.json`) sont reprises telles quelles comme grille par défaut tant qu'aucun tarif n'est enregistré.

## Galeries commerciales

Une **galerie** regroupe les pièces de plusieurs commerces (une rue, un village, un groupe d'amis…) sur une page publique commune,
**`/galerie/<identifiant>/`** (liste des galeries publiées : `/galerie/`), avec un annuaire des commerces, la recherche, les filtres
(commerce, univers, prix) et le tri. Chaque commerce garde sa boutique, son administration, son panier et son paiement : la galerie
présente, et chaque pièce renvoie vers sa fiche chez le commerçant (pas de panier commun).

- **Gestion** : portail → **« Galeries commerciales »** (`portail/galeries.php`, réservé à l'exploitant) : créer une galerie (nom,
  identifiant, slogan, couleur), cocher les commerces membres (★ = à la une), ajouter un **commerce d'ailleurs** (autre serveur, comme
  le Petit Chalet : son adresse, testée à l'ajout), publier. Une galerie non publiée s'ouvre par un lien d'aperçu secret (`?apercu=…`).
  Les galeries sont des fichiers `data/galeries/<identifiant>.json` (jamais envoyés par le déploiement). L'exploitant et l'administrateur
  d'une galerie peuvent la mettre en pause puis la réactiver : elle est masquée du public sans résilier l'offre ni retirer ses boutiques.
  L'exploitant peut supprimer la fiche et ses images (sans toucher aux boutiques), exporter un JSON portable avec les images ou importer cet
  export. L'import d'une nouvelle galerie la crée en brouillon sans comptes administrateurs ni offre Stripe ; le remplacement conserve les
  administrateurs et l'offre de la galerie cible. Les comptes et identifiants de paiement ne figurent jamais dans un export.
- **Adresse et type** : chaque galerie a un **type** (voir « Types de galerie »), qui décide de son vocabulaire, de ses lieux de vente et des plugins
  proposés à ses vendeurs, et peut recevoir un **nom de domaine propre** (voir « Nom de domaine propre d'une galerie ») en plus de
  `/galerie/<identifiant>/`.
- **Données** : chaque boutique sert son **catalogue public** en JSON à `/catalogue.php` (infos du commerce, logo, pièces non masquées et
  en stock, prix, photo de couverture, lien de la fiche). La galerie lit directement la base des commerces de ce serveur et le flux
  `/catalogue.php` des commerces d'ailleurs ; le catalogue regroupé est mis en cache 10 minutes (`data/galeries/cache/`, bouton
  « Actualiser » dans le portail). Un commerce injoignable est signalé et la galerie continue sans lui.
- **Code** : `includes/galleries.php` (galeries, catalogue, cache ; ne dépend que de `includes/tenant.php`), `galerie/index.php` (page
  publique), `catalogue.php`, `portail/galeries.php`. Routes : `.htaccess` (`/galerie/…`) et `router.php` en local.
- **Pas encore** : panier commun à plusieurs commerces, abonnements (vente / location d'une boutique seule ou en galerie), inscription
  libre des commerçants, logo et visuel propres à la galerie.

## Messagerie interne d'une galerie

Chaque galerie dispose d'une messagerie entre l'organisateur et ses interlocuteurs, sans compte à créer pour eux. Elle est activée
par défaut pour les six types de galerie ; la décocher dans **Types de galerie** retire le bouton et la carte de l'espace exposant,
ferme le lien invité et affiche une explication dans le portail.

- **Portail → Messages** (`portail/galerie-messages.php`) : la boîte de réception liste les conversations, leur dernier message et les
  messages non lus (le nombre s'affiche aussi dans le bouton « Messages » de la navigation, et la palette ⌘K y mène). On y répond, on
  ouvre une conversation avec un nom et une adresse e-mail (exposant, commerçant, prospect…), ou on supprime un fil. Depuis la liste des
  demandes ou la fiche d'un exposant, le bouton « Message » prépare la conversation avec ses coordonnées.
- **Côté exposant** : une carte « Messages avec l'organisateur » est ajoutée à l'espace personnel (`/galerie/exposant.php?g=…&t=…`), sous
  le pass. L'exposant écrit et suit les réponses sans mot de passe : son lien habituel sert de clé.
- **Côté invité** : une conversation ouverte par l'organisateur envoie un e-mail avec un lien personnel
  `/galerie/messages.php?g=…&i=…&t=…` (signé, sans mot de passe). La page affiche le fil et permet de répondre.
- **Notifications** : chaque message d'un visiteur prévient tous les administrateurs de la galerie ; chaque réponse de l'organisateur
  prévient la personne (e-mail via `ga_mail`, consultable en local dans `/portail/journal-local.php`).
- **Données et sécurité** : `data/galeries/<identifiant>.messages.json` (jamais envoyé par le déploiement), écrit sous verrou. Les fils
  d'exposant sont rangés par un identifiant dérivé du jeton de la demande ; les fils de contact ont un identifiant aléatoire et un jeton
  HMAC (`ga_token`), avec un jeton CSRF distinct pour le formulaire d'invité. Limites : 4 000 caractères par message, 300 messages par fil,
  1 000 fils par galerie, 20 messages par heure et par adresse IP. Pages en `noindex`, `no-store`, `no-referrer`.
- **Code** : `includes/gallery-messages.php` (données, jetons, notifications), `portail/galerie-messages.php` (boîte de réception),
  `galerie/messages.php` (fil d'un invité), carte et traitement POST dans `galerie/exposant.php`, entrée de menu dans
  `portail/_galerie-nav.php`, fonctionnalité `messages` dans `includes/gallery-types.php`, page autorisée en mode restreint
  (`GA_PAGES` dans `portail/_bootstrap.php`).

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
  par exemple `media`), `api` (`id`, `file`, `methods` GET/POST/PUT/PATCH/DELETE, `roles`), `cron` (`file` : tâches planifiées,
  voir plus bas), `agent_api` (`prefix`, `file` : appels d'un agent externe, voir plus bas), `sync` (plugin recopié d'un autre
  projet, voir plus bas) et `admin_assets` (`file`, `roles` : scripts et styles de
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
- **Tarifs et accès des modules payants** (`includes/addons.php`) : portail → **Plugins → Tarifs et modalités**, ou depuis la gestion
  d'une galerie. Configuration héritée **plateforme → galerie → boutique** ; un gestionnaire de galerie peut régler une offre pour toute
  sa galerie ou pour une boutique membre. Les prix et modalités proposés sont abonnement mensuel/annuel Stripe, paiement unique Stripe,
  paiement manuel avec contact de l'exploitant, ou activation gratuite ; essai (0 à 90 jours), accès inclus/indisponible, et conditions
  affichées dans le panneau du module. Une offre « incluse » installe et active le plugin pour les boutiques concernées. Les réglages sont
  conservés dans `data/saas/addons.json` ; l'ancien format de configuration des prix est repris automatiquement. Si une boutique appartient
  à plusieurs galeries qui ont défini des règles pour le même plugin, la première (ordre alphabétique de l'identifiant) est appliquée, puis
  la surcharge explicite de la boutique prime. Le pack Annonces Leboncoin/Vinted utilise aussi ce panneau ; les anciennes souscriptions
  de ce pack sont reprises automatiquement dans le nouveau registre d'accès à leur première ouverture. Dans une portée héritée, un champ vide
  conserve la valeur supérieure ; les cases « hériter » permettent de rétablir l'héritage d'un champ configuré localement.
- **Tâches planifiées** : une seule entrée dans la tâche planifiée de l'hébergeur (OVH : Hébergement → Tâches planifiées - Cron,
  langage PHP 8) lance `scripts/plugins-cron.php`. Sur un hébergement mutualisé OVH, la plus petite fréquence possible est **une fois par
  heure** (le champ Minutes est désactivé) : la tâche tourne donc à la minute choisie de chaque heure. Sur un serveur avec crontab, on
  peut descendre à 5 ou 15 minutes. Le script fait le tour des boutiques et, pour chaque
  plugin activé et à jour, exécute ses tâches (`cron` du manifeste) dans un processus séparé, avec `TENANT=<boutique>` : une tâche ne
  voit que sa boutique. Journal : `var/log/plugins-cron.log`. Options : `--plugin=<id>`, `--tenant=<boutique>`. En ligne de commande
  seulement (rien n'est servi par le web).
- **Agents externes** (`agent_api`) : un programme qui n'a pas de session (ex. l'agent de rendu de la Vidéo IA) appelle
  `/api/<préfixe>/…` d'une boutique (aussi sous `/<boutique>/api/…`) ; `.htaccess`, `router.php` et `LocalValetDriver.php` mènent
  à `plugin-agent.php`, qui choisit le plugin ayant déclaré ce préfixe, s'il est activé et à jour dans cette boutique. Pas de
  session ni de CSRF : **la route du plugin authentifie l'agent elle-même** (jeton) et n'ouvre que ses propres chemins.
- **Plugin recopié d'un autre projet** (`sync` : `tool`, le script d'extraction ; `source`, le nom du projet ; `record`, le fichier
  qui dit d'où vient la copie) : dans le **portail local** (Herd), la fiche du plugin montre si la copie est à jour (commit copié,
  commit actuel du projet, modifications pas encore commitées) et propose **« Recopier depuis … »**. Le bouton lance le script
  d'extraction (PHP en ligne de commande), puis affiche son rapport et les fichiers du plugin qui diffèrent du dernier commit.
  Rien ne part chez les boutiques avant le commit et le déploiement. En ligne, la fiche indique seulement de quel commit vient la
  copie : la recopie y est refusée.
- **Retour arrière** : redéployer l'ancien code met le plugin en pause (« base plus récente que le code ») ; pour revenir vraiment à
  l'ancienne version, remplacer la base de la boutique par la sauvegarde d'avant la mise à jour (site au repos ; les écritures faites
  depuis sont perdues).

## Retours testeurs : widget « Un retour ? », triage et relances

Un dispositif distinct des retours par module (`includes/addons.php`) : il recueille **tous** les bugs, idées et gênes des
boutiques testeuses, avec une capture d'écran facultative, et les suit jusqu'à la réponse.

- **Le widget** (`includes/feedback.php`, `assets/feedback-widget.*`, API `admin/feedback-api.php`) apparaît en bas à droite de
  l'administration des boutiques couvertes (`includes/admin-nav.php`) et dans le pied de page public (`includes/footer.php`) pour un
  **client connecté** à la boutique (`customer_session()`). Formulaire court (nature, gravité pour un bug, message
  dictable à la voix, capture), puis onglet **Mes retours** avec le statut et les réponses de l'équipe ; une pastille signale une
  réponse non lue, sans la marquer lue avant l'ouverture de l'onglet. L'onglet reçoit le module courant (`?p=`) et la page.
  Un client ne voit que **ses** retours (même e-mail, même boutique) et son état de lecture reste dans sa session ; l'équipe voit
  toute la boutique. Sur les pages publiques, l'assistance vendeur occupe le même angle : le widget se pose au-dessus d'elle.
- **Capture annotée** : « Capturer l'écran » passe par `getDisplayMedia` (HTTPS requis : l'utilisateur choisit l'onglet ou la
  fenêtre), fige l'image dans un `<canvas>` puis ouvre l'éditeur intégré — crayon, cadre, flèche, quatre couleurs, annuler le
  dernier trait, tout effacer. Le PNG annoté (re-encodé en JPEG au-delà de 3 Mo) part avec le retour. À défaut de capture, on
  choisit une image puis « Annoter l'image ». Une seule demande de capture à la fois (drapeau `capturing`) : le refus côté
  macOS (Réglages Système → Enregistrement de l'écran) affiche un message d'aide et propose le repli par fichier. Aucune
  bibliothèque externe ; l'image ne quitte le navigateur qu'à l'envoi.
- **Dictée** : bouton « Dicter » (API Web Speech, `fr-FR`, HTTPS + autorisation du micro). La transcription s'ajoute au message,
  les résultats intermédiaires s'affichent, le moteur relance après un silence, et le bouton est masqué si le navigateur ne la
  propose pas. Un refus du micro affiche un message sans bloquer le formulaire.
- **Couverture** : portail → **Retours testeurs** → *Programme testeurs*. On coche des boutiques et/ou des galeries (tous leurs
  membres locaux), on peut inclure automatiquement les testeurs déjà déclarés sur les modules « En test » (Tarifs des plugins), ou
  ouvrir le widget à toutes les boutiques. Le même écran règle l'e-mail d'alerte et le délai de relance.
- **Triage** (`portail/retours.php`) : filtres statut/type/boutique/période/recherche, mise à jour statut et priorité, note interne,
  réponse par e-mail (le testeur la voit dans l'historique du widget), journal du suivi, participation par boutique (actif,
  silencieux, jamais), export CSV. Les captures se consultent via `portail/retour-media.php?id=…`, réservé au portail.
- **Relances** : le bouton du portail, ou chaque jour `php scripts/feedback-reminders.php` (`--dry` pour compter sans envoyer).
  Au plus un e-mail par période et par boutique, uniquement si une adresse de réponse est connue (identifiant de connexion du
  commerce ou compte). Comme pour les modules en test, planifier le script dans la tâche cron de l'hébergeur.
- **Données et sécurité** : `data/saas/feedback.json` (écriture sous verrou, remplacement atomique), `feedback-settings.json`,
  `feedback-reminders.json` et `feedback-media/` ne sont jamais servis directement. Boutique forcée côté serveur, jeton CSRF pour
  l'API (jeton de la session d'administration, ou jeton propre au client), limite de 10 envois par heure et par boutique pour
  l'équipe et de 5 par client, doublons (< 3 min) fusionnés entre retours d'un même auteur, 5 000 retours conservés au maximum.
  Tests : `tests/FeedbackTest.php` (`FEEDBACK_DATA_DIR` temporaire, e-mails neutralisés).

## Pack Communication : la médiathèque, l'éditeur et la publication d'electroboy80, portés tels quels (plugin `communication`)

Activé pour une boutique (portail → Plugins), le plugin remplace l'entrée « Médiathèque » du menu par **la médiathèque
d'electroboy80**, avec sa vraie interface (dossiers, recherche, filtres, tris, vues grille et liste, kit, sélection multiple,
doublons, dérivés, recadrages orphelins, variantes, préfixes, import par adresse, liens vidéo, webcam, envoi par morceaux, menu
« Actions » de chaque fichier…). La page classique `admin/media.php` reste accessible. Administrateurs et community managers y ont accès.

**Comment c'est fait (portage fidèle, sans réécriture).**
- `packs/communication/tools/extract-eb80.php` recopie depuis le dossier d'electroboy80 son `admin.html` (dans
  `assets/eb80/admin.html`), ses scripts annexes (`assets/eb80/js/`) et les fichiers serveur de la médiathèque (`eb80/api/` :
  `config.php`, `media.php`, `media-links.php`, `generate-web-images.php`, `settings.php`, et `eb80/reglages-publics.php`), avec
  quelques retouches listées dans le script : il s'arrête si l'une ne trouve plus son motif. `eb80/SOURCE.txt` dit d'où vient la
  copie. **Quand electroboy80 évolue (un bug corrigé, par exemple), on recopie** : portail local → Plugins → Communication →
  « Recopier depuis electroboy80 », ou `php packs/communication/tools/extract-eb80.php [--source=…/electroboy80]`. `--status`
  refait la copie en mémoire sans rien écrire : la copie est « à jour » quand l'empreinte des fichiers lus d'electroboy80 et des
  retouches est celle notée dans `SOURCE.txt` (modifications pas encore commitées dans electroboy80 comprises) ; une retouche
  cassée y est signalée d'avance (« À revoir » dans le portail). Si `node` est installé, la syntaxe des scripts retouchés est
  vérifiée avant toute écriture : une retouche qui casserait le JavaScript de la page arrête la recopie. Si une retouche ne trouve plus son motif, le script s'arrête **avant d'écrire quoi que ce soit** :
  la copie reste entière. Ensuite : relire `git diff packs/communication`, tester sur une boutique locale, commiter, déployer.
  Les scripts et styles que la page charge (`<script src="/js/…">`, `/plugin/…`) suivent d'eux-mêmes, même nouveaux : ils sont
  recopiés au même chemin sous `assets/eb80/`, avec une empreinte de leur contenu dans l'adresse (jamais d'ancienne copie en cache),
  et les modules d'interface de `plugin/` prennent les couleurs de la boutique ; un fichier chargé mais absent arrête tout. Ne
  suivent pas d'eux-mêmes : un nouveau contrôleur `api/` (à ajouter à la liste du script) et un correctif dans une fonction
  remplacée par `eb80/compat.php` (à reporter à la main).
- **Sources de l'éditeur** (`plugin/sources.js` d'electroboy80, panneau « Source des données ») : la boutique déclare ses propres
  sources intégrées, **« Pièce du catalogue »** (pièces en vente : nom, prix, catégorie, description, photos, lien de la fiche) et
  **« Promo en cours »** (pièces en promo, prix barré, visuel promo à jour en premier), à la place des événements, articles merch et
  partenaires d'electroboy80 (option `integrees` de sources.js, posée par `eb80-shim.js` dans `window.EB80SourceConfig`). Données :
  route `eb80` GET `/shop/pieces` et `/shop/promos` (`lib/sources.php`), lecture seule, boutique courante, informations publiques
  seulement. « Alimenter l'affiche » remplit titre, prix, catégorie, « Au lieu de … » et photo de fond. La configuration (clé
  `editor_sources_private`, onglet Sources éditeur) est lisible par l'équipe, community manager compris, et modifiable par
  l'administrateur ; on peut y ajouter des sources personnalisées. Dans les **OPTIONS** d'une source de la boutique, « Champs
  utilisés » choisit ce que reprend chaque emplacement : catégorie, description, prix, **détail court** (le petit texte de
  l'affiche) et étiquettes — plusieurs champs possibles pour ces deux derniers — parmi nom, référence, catégorie, nature,
  description, prix, prix barré, prix du neuf, prix à débattre, badge, état, marque, matières, couleurs, dimensions, **poids**
  (et, pour les promos, accroche et appel à l'action du visuel). Par défaut, le détail court reprend « Au lieu de … », l'état et
  les dimensions. Liste des champs : `champsDisponibles` dans `eb80-shim.js`, valeurs dans `lib/sources.php` (mêmes clés).
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
- **Éditeur visuel** (menu « Éditeur visuel », même cadre ouvert sur la page « editor » d'electroboy80) : affiche, carrousel,
  vidéo et reel, formats et zones de sécurité, styles rapides, couches libres, timeline, effets (`eb80-effects.js`), projets
  enregistrés, export vers la médiathèque, PNG, PDF. En haut de l'aperçu, le **projet ouvert** (nom, type Image / Carousel /
  Vidéo, heure du dernier enregistrement ; cliquer sur le nom pour le renommer) et le menu déroulant **📁 Projet** : Enregistrer
  (Ctrl+S), Ouvrir… (recherche, filtres, renommer, dupliquer, supprimer), Enregistrer sous…, Renommer…, Dupliquer…, Nouveau
  projet…, Exporter… ; le nom, le format d'export et la confirmation s'ouvrent dans une fenêtre. **Nom automatique** (case
  cochée par défaut dans la fenêtre du nom, aperçu en direct) : « base-format-type-date-lieu.proj », par exemple
  `promo-didier-super-9:16-event-16∕10-le-trabendo.proj` ; la base proposée est le nom de l'entité qui alimente l'image (espaces → tirets),
  le format en rapport (9:16, 1:1, 16:9, 3:2, 1.91:1 ; A4, A4-paysage pour l'impression), le type d'entité (event, merch,
  partenaire, piece, promo…), la date jour∕mois de l'entité (sinon celle écrite sur l'image) — avec « ∕ » (U+2215), car un
  vrai « / » est un séparateur de dossier pour le serveur —, puis le lieu s'il existe (celui de l'entité, sinon celui écrit sur
  l'image, hors texte par défaut). Le nom affiché dans la barre en haut de l'aperçu (type, nom,
  état) se met à jour au fur et à mesure du paramétrage (en couleur tant qu'il n'est pas appliqué) ; un projet nommé ainsi est renommé tout seul à l'enregistrement si le type, la date ou le format
  changent ; un nom saisi à la main (case décochée, ou renommage depuis la liste) ne bouge plus. Les projets créés depuis la
  fenêtre d'entrée de l'éditeur et ceux d'avant cette fonction sont nommés de la même façon à leur prochain enregistrement. Nouveau projet (après
  confirmation : image vierge, sans texte, couche, image de fond ni source) et **Exporter** : image fixe en PNG, JPEG, WebP ou PDF
  (téléchargée aussitôt), vidéo ou carousel en WebM ou MP4 (rendu dans le navigateur, téléchargé et enregistré dans la
  médiathèque ; MP4 enregistré directement par le navigateur — Chrome ou Edge récents, Safari —, sans ffmpeg sur le serveur,
  sinon WebM). Fichiers nommés d'après le projet. Le mode « Affiche » s'appelle désormais « Image ». L'affiche part des textes de la boutique (son nom, l'adresse
  de son site, « Nouveautés en boutique »…) au lieu de ceux d'Electroboy 80 ; les blocs gardent leurs libellés génériques. Le script
  vidéo par l'IA (`ai-generate.php`) passe par Gemini et le quota de la boutique. La **Vidéo IA** (voir plus bas) s'ouvre aux couleurs et avec les textes de la boutique. La conversion en MP4 par ffmpeg sur le serveur (electroboy80) n'existe pas dans la boutique : l'export MP4 se fait
  dans le navigateur (menu Exporter).
- **Palettes des visuels** (menu « Palettes des visuels », administrateurs) : la partie palettes de la page Apparence
  d'electroboy80. L'IA de la boutique (Gemini ou Claude selon API & Réseaux → IA, quota de la boutique ; simulateur
  `EB80_IA_SIMULATEUR`) compose **10 palettes** accordées aux couleurs du **thème de la boutique** (fond, surface, textes,
  bordure, accents : `EB80_PALETTE_BASE` lu par `eb80-shim.js`), chacune avec **5 nuances** ; noms et couleurs se modifient,
  « Enregistrer les palettes » les range (réglage `editor_palettes`). Dans l'éditeur, la section **Palettes** (à la place des
  styles rapides) applique une palette ou une nuance au visuel d'un clic (fond, titre, textes, logo, date, cadre). Contrôleur
  `palettes.php` recopié ; génération réservée à l'administrateur ; le community manager utilise les palettes.
- **Vidéo IA** (éditeur, mode Vidéo → « ✨ Vidéo IA ») : la fenêtre d'electroboy80, avec son aperçu dans le navigateur, aux
  couleurs de la boutique et avec ses textes (scènes titre, photos, mots-chocs, carte infos, fin, exemples : `EB80_VIA_TEXTES` dans
  `eb80-shim.js`, retouches de `video-ia.js`). Choisir une pièce ou une promo refait les scènes avec ses photos, son nom et son prix.
  Les deux **bandeaux défilants** (haut et bas) appartiennent à la scène Mots-chocs : champ « Bandeau défilant » de sa carte,
  vide = aucun bandeau (par défaut ; la composition d'origine affichait sinon les influences d'Electroboy 80).
  - **Scénario par l'IA** (« Proposer une vidéo », « 3 variantes », « Accorder la carte au visuel ») : `video-jobs-ai.php` recopié ;
    le prompt présente la boutique (nom, `ai.shop`) au lieu d'Electroboy 80 ; sans pièce choisie, le sujet est la boutique. L'IA
    se choisit dans **API & Réseaux → IA** (« IA du scénario de la Vidéo IA ») : **Gemini** (par défaut, clé de la boutique ou
    partagée) ou **Claude** (clé Claude de la boutique, réglage ★ rangé dans `.secrets/<boutique>/claude.key`, sinon clé partagée
    du portail → Clés API). Le coût entre dans le quota d'IA de la boutique ; Claude a ses tarifs au barème (portail → IA,
    « Claude, entrée / sortie », à vérifier chez Anthropic). Remplacement de l'appel : `viaClaude()` dans `eb80/compat.php`.
  - **Rendu MP4** (« Générer le MP4 ») : `video-jobs.php` recopié ; les demandes sont dans `data/com/<boutique>.db`. L'**agent de
    rendu** (script Node/Remotion sur une machine de l'équipe, dépôt `remotion-videos`) vient les chercher avec le jeton de la
    boutique (« Générer un jeton d'agent », administrateur seulement, affiché une fois, rangé haché) : `GET /api/video-jobs/next`,
    puis `POST /api/video-jobs/{id}/result` (le MP4 par morceaux), par `plugin-agent.php` et `api/agent.php`. Les morceaux attendent
    hors de `uploads/` (`data/com/tmp/<boutique>/`) ; le fichier assemblé doit être un MP4 (ou MOV) de 500 Mo au plus, sinon il est
    refusé ; il arrive dans `uploads/<boutique>/` (nom suffixé du numéro de la demande, jamais d'écrasement), donc dans la médiathèque.
    **À faire côté agent** : lui déclarer chaque boutique (adresse + jeton) ; ses compositions gardent leurs noms
    (`electroboy80-promo-concert…`).
  - **Simulateur** : `define('EB80_IA_SIMULATEUR', true);` dans `config.local.php` renvoie un scénario factice, sans appel à une IA
    (tests locaux, jamais en ligne).
- **Pages de la plateforme** : les fenêtres « Choisir dans la médiathèque » du hero, du diaporama, des bandeaux et des réglages
  gagnent « Toute la médiathèque… », et la galerie d'une fiche « Ajouter depuis la médiathèque… » (`assets/picker.js`). Le bouton
  ouvre **le sélecteur d'electroboy80** (mode « sélecteur » de l'admin portée, en surimpression). Le fichier choisi est inscrit dans
  `media_library` (route `media-adopt`), puis appliqué à la page.
- **Publication sur les réseaux** (menu « Publication », page « publish » d'electroboy80 ; administrateurs et community managers) :
  composer un post (texte, légende par réseau, texte proposé par l'IA via Gemini et le quota de la boutique), choisir un visuel
  (affiche de l'éditeur, médiathèque, carrousel, envoi), publier tout de suite ou programmer (une fois, ou à répéter) sur Facebook,
  Instagram, Threads et TikTok, avec l'historique des publications programmées. Contrôleurs recopiés : `rs-v2.php`,
  `rs-schedule.php`, `rs-tiktok-callback.php`, `social-feed.php`.
- **API & Réseaux** (menu « API & Réseaux », administrateurs seulement) : la page de réglages d'electroboy80 complète — IA,
  Réseaux sociaux (avec ses guides pas à pas), Connexion & envois, Recherche & billetterie, Sources éditeur, SEO. Sans doublon
  avec la plateforme (`lib/reglages.php`) : la **clé Gemini** est celle de la boutique (la même que dans le tableau de bord,
  `.secrets/<boutique>/gemini.key`) et les **clés Google / Facebook** de la connexion sociale sont celles de « Connexion sociale »
  (`.secrets/<boutique>/oauth.json`) ; le serveur les lit et les écrit chez la plateforme, ne renvoie jamais un secret (« •••••• »
  s'il existe ; le laisser tel quel le garde) et ne les range jamais dans la base d'electroboy80. Ces champs sont marqués ★ avec un
  lien vers leur page. Les réglages qu'aucune fonction de la boutique n'utilise (clé Claude, SMTP et e-mail de test, WhatsApp, SMS,
  billetterie, recherche) sont grisés « Non utilisé par la boutique » et ignorés à l'enregistrement. Le bouton ✨ IA du SEO passe
  par Gemini et le quota de la boutique (le champ « clé Claude » qu'il réclame reçoit une valeur neutre, jamais enregistrée).
  Comme dans electroboy80, **chaque boutique colle ses propres jetons** (page
  Facebook, compte Instagram professionnel, Threads, TikTok) ; ils restent dans `data/com/<boutique>.db`. Un community manager publie
  avec ces jetons sans jamais les lire (l'API lui renvoie `••••••`) ; renouveler un jeton, le diagnostic, les tests et les réglages
  sont réservés à l'administrateur. L'adresse de retour TikTok à déclarer est affichée sur la page (celle de la boutique).
- **Programmations** : elles ne partent que par la tâche planifiée (`cron/rs-schedule.php`, lancé par `scripts/plugins-cron.php`) ;
  l'adresse web d'electroboy80 qui les déclenchait répond 403. Un verrou par boutique (`data/com/<boutique>.cron.lock`) empêche deux
  passages simultanés de publier deux fois. Les heures sont celles du fuseau de la boutique (`timezone`, sinon Europe/Paris).
  **À mettre en place sur OVH** au premier déploiement : une tâche planifiée qui lance
  `php <dossier du site>/scripts/plugins-cron.php` toutes les 5 minutes (ou 15, selon l'offre).
- **Simulateur** : avec `define('EB80_RS_SIMULATEUR', true);` dans `config.local.php`, aucun appel ne part vers Meta ou TikTok ; les
  appels sont écrits dans `data/com/<boutique>-simulateur.log` et reçoivent une réponse factice (« Page simulée », `sim_…`). C'est le
  réglage des tests locaux ; jamais en ligne.
- **Limites** : Meta n'accepte que des médias à une adresse publique (pas depuis un poste local) ; publier pour d'autres comptes
  que les siens demande la validation de l'application par Meta (App Review) — d'ici là, chaque boutique crée sa propre application
  ou l'on teste avec des comptes de test. La vidéo exportée par l'éditeur reste en WebM (Instagram et TikTok demandent du MP4).
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
- **Avatar et logo** (`admin/profile.php`, compte principal compris) : une photo importée
  (JPG, PNG ou WebP, 8 Mo max) ou un **selfie pris avec la caméra** (`assets/avatar-selfie.js`,
  caméra avant par défaut, bascule arrière si plusieurs caméras, aperçu avant validation) est
  transformé par l'IA en illustration fidèle détourée ; le coût est annoncé avant et la génération
  est décomptée du crédit IA. L'avatar généré entre dans la **médiathèque**
  (source « Avatar du vendeur ») et y reste utilisable comme logo ; « Utiliser comme logo de boutique »
  compose les trois déclinaisons (en-tête, vertical, carré) avec le nom du commerce, sans nouveau
  crédit, et remplace les logos actuels. Chaque compte de l'équipe a son propre avatar (réglages de
  la boutique, clé `avatars`).
  La prise de vue exige une connexion sécurisée (https) ; sinon le bouton reste masqué et
  l'import de photo reste disponible.
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

**Galeries** : les mêmes boutons « Continuer avec… » (clés de la plateforme, e-mail vérifié exigé) existent sur trois pages de galerie. **Création de galerie** (`/galerie/creer.php`) : on choisit l'offre et le nom, puis le réseau social fournit l'e-mail (contexte `gcreate` : la demande est mémorisée en session puis rejouée au retour). **Connexion des administrateurs** (`/galerie/admin.php`, contexte `gadmin`) : l'adresse vérifiée doit être celle d'un administrateur déjà déclaré dans une galerie. **Espace visiteur de la galerie** (`/galerie/visiteur.php`, contexte `gvisitor`) : inscription et connexion immédiates, sans e-mail à ouvrir, avec la case de consentement cochée ; le membre reçoit les points de bienvenue. L'espace visiteur du plugin de chaque boutique garde sa connexion par lien e-mail.

**Espace visiteur d'une boutique** (plugin « Espace visiteur & fidélité », `/visiteur.php`) : les mêmes boutons « Continuer avec… » sont proposés sous le formulaire de connexion (contexte `customer` avec `next=/visiteur.php`, réglages « Connexion sociale » de la boutique : comptes clients). La case d'accord doit être cochée avant le clic ; au retour, le hook `packs/espace-visiteur/hooks/visiteur_social.php` crée ou retrouve le membre (points de bienvenue crédités à l'ouverture) et renvoie sur son espace avec son lien personnel. Le chemin `next` peut arriver préfixé par celui de la boutique (`/naty/visiteur.php`) : `oauth/start.php` le ramène à la forme canonique. **Les boutons n'apparaissent que si un fournisseur est activé avec ses clés** (portail → Connexion sociale pour la plateforme, ou Administration → Connexion sociale pour les clés propres d'une boutique) ; sans clés, aucun bouton nulle part.

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


## Administrateurs de galerie

Chaque galerie commerciale peut avoir ses propres administrateurs (jusqu'à 10), créés par l'exploitant dans **Portail → Galeries → [galerie] → Administrateurs de la galerie** (adresse e-mail, mot de passe provisoire envoyé par e-mail et affiché une fois). Ils se connectent sur `/galerie/admin.php` et n'accèdent qu'à **leur** galerie (mode restreint de `portail/_bootstrap.php` : seules les pages `galeries.php`, `galerie-tableau.php`, `galerie-prospects.php` et `galerie-compte.php` sont ouvertes) :

- **Présentation et commerces** : mêmes réglages que le portail (nom, apparence, commerces membres, publication) ; ni création, ni suppression de galerie, ni gestion des comptes.
- **Tableau de bord** : visiteurs et pages vues de la page publique (sans cookie), affichages et clics des promos, pièces et commandes par commerce (commerces de ce serveur), prospects.
- **Prospects** : relance des commerçants en cours d'inscription pour cette galerie (3 relances au plus, 3 jours entre deux), liste libre de commerces à recruter (messages avec lien de désinscription obligatoire) et abonnés aux nouveautés de la galerie (double confirmation, un message par jour au plus).

Comptes dans `data/galeries/<id>.json` (clé `admins`, mots de passe hachés) ; activité, prospects et abonnés dans `data/galeries/<id>.{stats,prospects,subs}.json` (jamais déployés). Logique : `includes/gallery-admin.php`.

## Offres de galerie (produit à vendre)

La création d'une galerie commerciale se vend sur `/galerie/creer.php` (lien « Créer ma galerie » sur la liste des galeries). Trois offres par défaut, modifiables par l'exploitant dans **Portail → Offres galerie** (`portail/offres-galerie.php`, stockées dans `data/saas/galeries-offres.json`) :

- **Test gratuit** : 14 jours, 3 commerces, sans carte bancaire, un seul test par adresse e-mail (formulaire protégé par un champ piège et une limite de fréquence).
- **Essentiel** : 10 commerces, abonnement mensuel ou annuel (Stripe Checkout, clé de la plateforme).
- **Pro** : 30 commerces, même fonctionnement.

La galerie est créée **non publiée** avec son administrateur (mot de passe provisoire envoyé par e-mail). Statuts (`go_status()`) : essai, actif, expiré, résilié ; une galerie « sans offre » (créée à la main) reste en ligne sans limite. Une galerie expirée ou résiliée disparaît de la liste et sa page publique renvoie 404 (le lien d'aperçu reste valable). La limite de commerces ne s'applique qu'aux administrateurs de galerie, pas à l'exploitant. L'administrateur voit **Mon offre** (`portail/galerie-offre.php`) : changer d'offre (l'ancien abonnement Stripe est fermé), résilier en fin de période. Le webhook `inscription/webhook.php` traite `gallery_offer` et `customer.subscription.deleted`. Logique : `includes/gallery-offers.php`.

## Commissions et Stripe Connect

Réglage dans **Portail → Commissions** (`portail/commissions.php`, exploitant seulement) ; logique dans `includes/commissions.php` (suivi) et `includes/connect.php` (paiement).

- **Quatre commissions**, chacune à 0 % par défaut : plateforme sur les ventes des boutiques, galerie sur les ventes de ses membres, part de la galerie sur les abonnements qu'elle a recrutés. Taux modifiables par galerie, attribution boutique → galerie, versement minimum, date de départ du suivi (`since`).
- **Stripe Connect** (modèle « destination charge ») : une boutique **hébergée avec le portail** dont le compte Connect est prêt encaisse via la plateforme avec `application_fee_amount` + `transfer_data[destination]`. La part de la galerie reste sur la plateforme puis part par virement Stripe (Transfer). Base de calcul : marchandises après remise, **hors livraison**. Les commandes portent `payment_via`, `connect_fee_cents`, `connect_gallery_cents`. Les boutiques à clés propres (ex. Petit Chalet) ne changent pas : leurs commissions sont seulement **suivies, à facturer** hors Stripe.
- **Journal** `data/saas/commissions.jsonl` (ventes, abonnements, remboursements ; `collected` = argent détenu par la plateforme), état et versements dans `commissions-state.json` / `commissions-payouts.json`, comptes dans `connect.json`, taux publiés aux boutiques dans `connect-rates.json` (seulement une fois le compte validé).
- **Relevés** par galerie, versement « par Stripe » (limité au solde réellement encaissé et au minimum) ou « déjà viré » (référence obligatoire), export CSV (`?csv=all|slug&from&to`). Chaque administrateur de galerie voit ses commissions en lecture seule dans son tableau de bord.
- **À faire avant usage réel** : activer Connect dans le tableau de bord Stripe, essayer en mode test (KYC, frais, litiges, remboursements), faire valider TVA et conditions générales ; les autres encaissements (caisse, réservation, retrait) exigent encore les clés propres de la boutique.

## Plan d'implantation d'une galerie

Chaque galerie peut dessiner où se trouvent ses commerces : **Portail → Galeries → [galerie] → Plan des commerces** (`portail/galerie-plan.php`, ouvert aussi aux administrateurs de la galerie). On cherche une adresse (géocodeur IGN, secours Photon hors de France), puis on place des **repères**, **rectangles**, **zones** et **tracés** avec les outils à gauche de la carte (Leaflet 1.9.4 + Leaflet-Geoman 2.17.0, chargés depuis cdnjs et jsDelivr). Un clic sur un élément permet de lui donner un nom, une couleur, une précision, un **type de zone** (les lieux de vente du type de galerie : boutique, stand, emplacement…, sinon un ancien type rue/hall/allée/cour/parking/quartier/autre) et de le **lier à un commerce membre** ; le fond est « Plan » ou « Satellite » (tuiles IGN Géoplateforme).

- **Données** : clé `map` de `data/galeries/<id>.json` (`center`, `zoom`, `base`, `items[]` = `kind` marker/polygon/line, `pts` en latitude-longitude, `ztype`, `label`, `note`, `color`, `shop`). Tout est revalidé côté serveur par `gallery_map_normalize()` (`includes/gallery-map.php`, avec les lieux du type de la galerie) : 300 éléments et 60 points au plus, couleurs `#rrggbb`, commerce lié limité aux membres de la galerie.
- **Page publique** : section « Plan des commerces » de `/galerie/<id>/` (ancre `#plan-commerces`). La carte ne se charge **qu'au clic** du visiteur (aucune requête de tuiles avant), et les noms sont insérés en texte brut (pas de HTML). Un repère ou une zone liée à un commerce ouvre sa boutique. Les textes emploient le **lieu de vente du type** (« Emplacement libre », « Stand 12 »…).
- **Fonds de carte** : WMTS **IGN Géoplateforme** (plan ou orthophoto), sans clé d'API, avec secours automatique CARTO/Esri si l'IGN ne couvre pas le lieu. Les tuiles `tile.openstreetmap.org` sont volontairement exclues (politique d'usage) et Google Maps n'est pas utilisé (clé facturable, fond non recopiable). Les attributions sont affichées.

## Réservation : arrhes ou acompte

Le module « Réservation » (Administration → Réservations, `packs/reservation-arrhes`) propose deux types de versement, au choix de la boutique (réglage « Type de versement ») :

- **Arrhes** (par défaut) : chacun peut renoncer — le client qui renonce perd les arrhes, la boutique qui renonce rend le double (art. L214-1 du Code de la consommation).
- **Acompte** : la vente est ferme, le solde reste dû et la boutique peut l'exiger. Si le client est absent, l'acompte est conservé et le solde reste dû.

Chaque type a son texte de conditions modifiable (`{arrhes}`/`{acompte}`, `{pourcent}`, `{piece}`, `{date}`). Le type et le texte sont figés dans chaque réservation au moment où elle est faite ; changer le réglage n'affecte donc pas les réservations en cours. Les mots (fiche pièce, page Stripe, e-mails, tableau d'administration) suivent le type de la réservation. La colonne `mode` de `ra_reservations` est ajoutée automatiquement à la première utilisation, sans « Mettre à jour » du module. Les conditions fournies sont un modèle : à faire valider (droit de rétractation de 14 jours des ventes à distance, nature juridique du versement).

### Plan : surfaces, numérotation, division, fond masqué, localisation de la marchandise

La page « Plan des commerces » comporte une aide pas à pas (dépliable) et :

- **Catalogue de surfaces** (`map.catalog`) : tailles d'emplacement en mètres (ex. « Stand 3 × 2 m »). « Poser » dessine un rectangle à l'échelle réelle là où l'on clique, avec un angle réglable ; l'échelle de la carte est affichée. Trois exemples sont proposés au départ.
- **Numérotation** : un numéro par élément (`num`, 12 caractères au plus), préfixe et compteur « prochain numéro » utilisés à la pose et à la division, et « Numéroter tout » (ordre de création, gauche→droite/haut→bas, ou haut→bas/gauche→droite ; seulement les emplacements sans numéro, ou tous).
- **Division d'une zone** à 4 coins en lignes × colonnes (jusqu'à 40 × 40, 600 éléments au plus sur le plan) : la zone est remplacée par les cases, qui gardent couleur et commerce lié et reçoivent chacune le numéro suivant. La surface en m² de chaque zone s'affiche dans sa fiche et dans la liste des emplacements.
- **Masquer le fond de carte** (`base: "none"`) : plan seul sur une grille, sans requête vers OpenStreetMap ni Esri ; vaut aussi pour la page publique.
- **Localisation de la marchandise** (`map.places[<commerce>]` : adresse, code postal, ville, emplacement, précision) : saisie à la main ou reprise de l'adresse du repère du commerce (recherche inverse Nominatim). Pour un commerce de ce serveur, `gallery_member_place()` la lit dans la première galerie qui la renseigne et `packs/annonces-marche/lib/catalog.php` l'ajoute à chaque annonce exportée (clé `location`, dont `address`). Le bot Leboncoin l'utilise comme « adresse du bien » à la place de celle du profil et remplace l'adresse mémorisée par Leboncoin si elle diffère (`remplir_adresse(..., forcer=True)` dans `lbc_formulaire.py`). Les commerces d'ailleurs (autre hébergement, comme le Petit Chalet) ne sont pas concernés : leur export reste sans `location`.
- **Plein écran** : le bouton « ⛶ Plein écran » ouvre le plan dans une modale qui occupe tout l'écran, avec le catalogue de surfaces et la numérotation dans une colonne à gauche (les mêmes cartes sont déplacées, rien n'est perdu à la fermeture). Échap termine d'abord une pose en cours, puis ferme la modale ; Tab reste à l'intérieur tant qu'elle est ouverte ; sur petit écran la colonne passe sous la carte.
- **Rotation** : la fiche d'une zone ou d'un tracé propose ↻ / ↺ de 1°, 15° ou 90° et un angle précis (rotation autour du centre, calculée en mètres : longueurs et surface sont conservées). L'outil de rotation de la carte permet aussi de tourner à la souris. L'angle de pose du catalogue règle l'orientation des surfaces à leur création.

## Espace visiteur & fidélité (plugin `espace-visiteur`)

Module payant avec essai (comme les autres, voir `includes/addons.php`), à activer boutique par boutique (portail → Plugins) puis à régler dans **Administration → Espace visiteur**.

- **Web app des visiteurs** : page `/visiteur.php` de chaque boutique (`visiteur.php` + `packs/espace-visiteur/front/visiteur_app.php`, habillée comme la boutique, mobile d'abord). Rubriques : **Fidélité** (points, paliers, récompenses, historique), **Mes alertes** (module Alerte chineur), **Réservations** (billets de Retrait sur stand, réservations avec arrhes ou acompte), **Scanner un QR** (module QR Code Story : lecture par l'appareil photo, uniquement vers une page de la même boutique). Une rubrique d'un autre module n'apparaît que si ce module est activé, à jour et sous licence ; sans le plugin ou sans licence, la page renvoie 404.
- **Accès sans mot de passe** : le visiteur saisit son e-mail (et coche son accord à la première fois) ; un lien personnel `…/visiteur.php?t=<jeton>` lui est envoyé. La réponse est la même que l'adresse soit connue ou non, 5 demandes par heure et par visiteur, champ piège anti-robot. Le jeton est gardé dans le navigateur ; « Effacer mon espace et mes points » supprime le membre, son journal et ses récompenses (droit à l'effacement) ; l'administration peut aussi effacer un membre.
- **Points** : `points par euro × montant des pièces payé en ligne (hors livraison, après remises)`, crédités au paiement (événement `order_paid`) au membre dont l'adresse est celle de la commande, **une seule fois par commande**. Points de bienvenue après le premier passage par le lien (adresse vérifiée) ; seules les adresses vérifiées gagnent des points. Ajustements manuels (+/−, avec motif) dans la liste des membres, export CSV.
- **Récompenses** : paliers « N points = X € de remise » (jusqu'à 5). Le visiteur échange ses points contre un code `FID-XXXXXXXX`, appliqué par « Utiliser sur mon panier » (`/cart.php?recompense=CODE`, mémorisé en session) : la remise s'ajoute à celle des paliers du panier, au moins 1 € reste à payer, le code est réservé à la commande dès l'ouverture du paiement (50 min) puis soldé au paiement ; il ne sert qu'une fois.
- **Liens** : bandeau « Votre espace visiteur » sur la page d'accueil de la boutique (emplacement `visiteur_link`) et lien « Espace visiteur ↗ » sur la carte du commerce dans la page de chaque galerie (clé `visitor_url` du catalogue, présente seulement si le plugin est actif et sous licence dans cette boutique).
- **Socle** : nouveau filtre de plugins `plugin_filter()` (`includes/plugins.php`, hooks `packs/<id>/hooks/<sujet>.php` qui retournent la valeur modifiée) utilisé pour `cart_discount` ; nouvel événement `checkout_created` (commande ouverte au paiement). Les adresses `/api/…` des modules (reçus, billets, retours de paiement, alertes, espace visiteur) reçoivent maintenant le préfixe du commerce hébergé avec le portail (`shop_abs_url()`), sans quoi elles pointaient vers une autre boutique.
- **À faire valider de votre côté** : information et accord des membres (l'adresse sert uniquement au programme), durée de conservation, conditions du programme de fidélité ; les récompenses sont des remises que vous financez.

## Types de galerie

Le portail (**Types de galerie**, `portail/offres-galerie.php`) propose plusieurs produits à partir du même moteur : **Galerie marchande**, **Marché aux puces**, **Vide-grenier** et **Quartier marchand** (nom proposé pour réunir les commerçants d'une rue, d'un quartier ou d'un village ; modifiable), plus les types que l'exploitant ajoute. Chaque type a (registre `includes/gallery-types.php`, réglages dans `data/saas/galeries-types.json`) :

- ses **dénominations** (galerie / marché / vide-grenier ; commerces / marchands / exposants / commerçants ; lieux de vente ; titres, bouton « Rejoindre », page de création…), appliquées à la page publique, à la page de création, aux e-mails et au portail ;
- ses **lieux de vente** (boutique, stand, emplacement, vitrine, rue… : jusqu'à 20, avec libellé, unité — emplacement, mètre linéaire, m² — et prix indicatif) : ils forment les types de zone proposés dans le plan d'implantation et préremplissent son unité et son tarif ; les anciens types de zone (rue, hall, allée, cour, parking, quartier, autre) restent acceptés dans tous les types ;
- ses **plugins vendeurs** : pour chaque module payant de `addon_defaults()`, « selon la plateforme » (le ciblage global s'applique), « proposé », « inclus » (gratuit, sans essai) ou « masqué » aux vendeurs des boutiques de ce type de galerie ; un choix explicite prime sur le ciblage global du module ;
- ses **fonctionnalités** cochées : plan, tarifs par zone, demandes d'emplacement des exposants, prospects, tableau de bord, abonnés (les boutons du menu d'une galerie et les blocs du plan en dépendent) ;
- ses **trois offres** (test gratuit, deux abonnements) avec prix mensuel et annuel, nombre de membres et durée d'essai propres ;
- sa **page de création** : `/galerie/creer.php` propose le choix du type, `/galerie/creer.php?type=<type>` mène directement au bon produit (offres et vocabulaire du type). Le test gratuit est limité à une fois par adresse et par type.

Le type d'une galerie est la clé `type` de `data/galeries/<id>.json` (galerie marchande si absent) et se change dans le tableau « Type et offre de chaque galerie ». Les offres réglées avant l'arrivée des types restent celles de la galerie marchande. Un changement d'offre ou un paiement Stripe retrouve les offres du type de la galerie (métadonnée `gallery_type`).

Chaque galerie peut aussi recevoir un **nom de domaine propre** (champ « Nom de domaine propre » dans l'onglet Galeries commerciales, clé `domain` du fichier de la galerie). Saisi sous la forme `galerie-exemple.fr` (les `https://`, `www.` et chemins sont retirés ; un domaine de second niveau est exigé), il doit être unique : le portail refuse un domaine déjà attribué à une autre galerie. Côté serveur, `accueil/index.php` et `galerie/index.php` reconnaissent l'en-tête `Host` et servent la galerie à la racine du domaine (avec ou sans `www`), quel que soit le déploiement. Les adresses publiques, liens de partage, e-mails et `canonical` utilisent alors `https://<domaine>/` ; sans domaine, ils gardent `<origine>/galerie/<identifiant>/`. **Le DNS et le certificat HTTPS restent à faire côté hébergement** : le domaine doit pointer vers le serveur et être couvert par un certificat. Les exports JSON n'emportent pas le domaine (risque de collision) ; un import dans une galerie existante conserve le sien.

## Plan : zones nommées, tarifs et exposants

- **Zones** : un polygone dessiné peut être une **zone** avec un nom ; le type de zone est choisi parmi les **lieux de vente du type de galerie** (boutique, stand, emplacement, vitrine, rue…), avec repli sur les anciens types (rue, hall, allée, cour, parking, quartier, autre). Choisir un lieu du type préremplit l'unité de tarification et le prix indicatif. Les **emplacements** qu'elle contient lui appartiennent (calculé côté serveur : plus petite zone contenant leur centre). Les zones sont affichées en pointillés avec leur nom ; la numérotation peut recommencer à 1 dans chaque zone (le N° de la zone sert de préfixe).
- **Tarifs par zone** : par emplacement, par mètre linéaire de façade (premier côté de l'emplacement) ou par m², avec un tarif **professionnel** facultatif. Le tarif d'un emplacement hérite de sa zone ; il s'affiche dans la fiche, la liste et le plan public (si le type a la fonctionnalité « tarifs »).
- **Exposants** : réglage « Exposants : choisir son emplacement » du plan. Sur la page publique, le bouton « Je suis exposant » colore les emplacements (libre, demandé, attribué) ; l'exposant choisit un emplacement libre, remplit sa demande (accord à l'enregistrement de ses coordonnées, champ anti-robot, 6 demandes par heure et par connexion, 2 demandes en attente par adresse) et reçoit un e-mail avec un lien d'annulation (confirmation demandée). Une demande en attente bloque l'emplacement le nombre de jours réglé. L'organisateur la traite dans **Exposants** (accepter, refuser, libérer ; e-mail à l'exposant ; export CSV). Les demandes sont dans `data/galeries/<id>.slots.json`. Le règlement se fait hors ligne. Le lien personnel de l'e-mail ouvre l'**espace exposant** (`galerie/exposant.php?g=<galerie>&t=<jeton>`) : statut de la demande, plan et itinéraire, installation sur le téléphone, annulation, et — une fois la demande acceptée — le **pass QR** de confirmation (voir « Pass QR de confirmation (appli exposant) »).
- **Plein écran** : le catalogue et la numérotation passent dans une colonne qui défile sans déborder sur la carte.

## Page d'accueil de la plateforme

`accueil/index.php` (racine du domaine du portail) est organisée par parcours : **Je vends** (boutique et ses formules), **J'organise** (les types de galerie, leurs fonctionnalités et prix, avec un tableau comparatif construit à partir des types actifs), **J'expose** (galeries dont les demandes d'emplacement sont ouvertes et qui ont encore des emplacements libres), **Je découvre** (galeries avec leur type, boutiques, annuaire), plus les **options à la carte** (modules de `addon_defaults()`), une barre d'ancres, la FAQ et un bandeau « Déjà client ? ». Tout est lu dans les réglages (types de galerie, offres, modules) : modifier un prix, un nom de type ou une fonctionnalité dans le portail met la page à jour.

## Nom de domaine propre d'une galerie

Portail → Galeries commerciales → *Gérer* → **Publication** → « Nom de domaine propre ». Le champ est facultatif, par galerie (`domain` dans `data/galeries/<identifiant>.json`) : laissez vide pour garder `/galerie/<identifiant>/`. Saisi avec ou sans `https://`, `www.` ou chemin, il est normalisé (ex. `https://www.Galerie-Exemple.fr/` → `galerie-exemple.fr`) et refusé s'il n'a pas de point ou s'il appartient déjà à une autre galerie. La galerie est alors servie à la racine du domaine (accueil et `/galerie/…`, avec ou sans `www`) et les liens publics (partage, e-mails, `canonical`) pointent vers `https://<domaine>/`.

Ce que le site ne fait pas : l'enregistrement DNS et le certificat HTTPS. Faites pointer le domaine vers ce serveur (enregistrement `A`/`AAAA`, ou `CNAME` vers l'hébergement) puis activez le certificat (Let's Encrypt chez l'hébergeur, ou certificat du proxy) avant d'annoncer l'adresse. En local : `curl -H 'Host: galerie-exemple.fr' http://127.0.0.1:8123/`. Les exports JSON n'emportent pas le domaine (risque de collision) ; un import dans une galerie existante conserve le sien.

## Import de prospects et de clients

Deux entrées, un même moteur (`includes/contacts-import.php`, écrans dans `includes/contacts-import-ui.php`) :

- **Boutique** : Administration → Comptes → « Importer des contacts » (`admin/contacts-import.php`, administrateurs seulement). Les contacts deviennent des fiches **prospects** ou **clients** (au choix) avec leur téléphone, leur société et leur origine dans les notes ; source « Import (fichier ou CRM) ». Une fiche exige une adresse e-mail : les lignes sans e-mail sont écartées avec leur motif.
- **Galerie** : Prospects → « Importer des contacts » (`portail/galerie-import.php`). Les contacts rejoignent la liste des commerces à recruter (le téléphone est maintenant conservé) ; un contact sans e-mail mais avec téléphone est accepté.

**Sources** : fichiers **CSV** (séparateur `;` `,` tabulation ou `|` détecté, UTF-8 ou Windows-1252 d'Excel), **Excel `.xls` / `.xlsx`** (première feuille) et carnet **vCard** `.vcf` (export des contacts d'un téléphone), ou lecture directe de **HubSpot**, **Brevo**, **Mailchimp** et **Pipedrive** avec leur clé API. Fichiers jusqu'à 10 Mo, 5 000 contacts et 200 colonnes. Zoho CRM, Google Contacts, Outlook, Odoo et Salesforce passent par leur export CSV : leurs en-têtes sont reconnus (« Modèle reconnu » dans l'aperçu), les colonnes peuvent aussi être choisies à la main.

**Déroulement** : fichier ou CRM → **aperçu** (à créer, déjà présents, écartés et pourquoi, 15 premières lignes, colonnes modifiables) → confirmation → résultat. Les en-têtes sont associés aux champs du site ; une colonne sans correspondance peut créer un champ personnalisé (par exemple « Site web »), conservé dans la fiche d'un compte ou d'un prospect de galerie et modifiable depuis sa liste. L'aperçu est gardé 1 h dans `data/imports/` (non servi) puis supprimé ; une fois l'import fait ou annulé, il est effacé.

**Règles** : une fiche par adresse (jamais de doublon, y compris dans le fichier) ; les téléphones sont mis au format international (06 12 34 56 78 → +33612345678) et ceux qui sont invraisemblables sont ignorés ; les **désinscrits, bloqués ou nettoyés des CRM** (unsubscribed, cleaned, blacklisted, opt-out…) ne sont jamais importés ; l'importateur confirme avoir le droit de contacter ces personnes ; « compléter les fiches existantes » ne renseigne que ce qui manque (nom, téléphone et champs personnalisés) et ne change jamais un rôle ni un nom existant.

**Clés des CRM** : collées à chaque lecture, utilisées uniquement pendant la requête (adresses des API fixées en dur ; Mailchimp : datacenter tiré de la clé et validé), ni enregistrées ni journalisées. Aucun jeton OAuth n'est géré : pour les CRM sans clé simple, passer par l'export CSV. À faire valider de votre côté : l'information des personnes importées (RGPD) et la conservation de leurs données.

## Plan : découpage d'une zone en parcelles

Dans l'éditeur de plan (portail → galerie → Plan), sélectionnez une zone ou un emplacement à 4 coins : la fiche « Découper en parcelles » apparaît.

- **Par nombre** (parcelles par rangée × rangées) **ou par dimensions** (largeur × profondeur en mètres, le nombre est déduit de la taille de la zone). Un aperçu indique le nombre et la taille des parcelles avant de valider.
- **Allée au milieu** : un couloir de largeur réglable (3 m par défaut) sépare deux blocs de parcelles qui se font face ; sens dans la longueur ou dans la largeur de la zone ; l'allée est dessinée comme une zone de type « allée » (case décochable) sans tarif.
- Une **zone** (rue, hall…) est conservée et reçoit les parcelles à l'intérieur (elles en héritent le tarif) ; un **emplacement** est remplacé par ses parcelles.
- Si « numéroter » est coché, les parcelles reçoivent les numéros suivants, du nord vers le sud et de gauche à droite. Limite : 600 éléments par plan.

Aucun changement de format : les parcelles sont des emplacements ordinaires du plan. Code : `portail/galerie-plan.php` uniquement.

### Aperçu, aide au calcul et simulation du CA (découpage)

- **Aperçu avant validation** : dès qu'une zone à 4 coins est sélectionnée, les parcelles (et l'allée) apparaissent en pointillés bleus sur la carte, avec leurs futurs numéros ; elles bougent à chaque changement d'option et ne sont créées qu'au clic sur « Découper » (case « Aperçu sur la carte » pour le masquer). Le sens de découpage se règle (« parcelles alignées le long du côté le plus long / le plus court »).
- **Aide au calcul** : on indique la largeur mini et maxi d'une parcelle (ex. 2 à 4 m) ; la liste donne toutes les divisions entières possibles (nombre par rangée, largeur, nombre total de parcelles, prix, €/m²). « Utiliser » applique une ligne. La ligne ★ est la meilleure marge au scénario central ; à marge égale (tarif au ml ou au m²), on retient une largeur ronde (multiple de 0,5 m).
- **Simulation du CA** : tarif et unité repris de la zone (modifiables pour tester un autre positionnement), coûts fixes, coût par parcelle occupée, frais en % du CA, et trois scénarios (bas / central / haut : taux d'occupation et écart de prix). Pour la division en cours : CA, coûts, résultat de chaque scénario sur l'ensemble du plan (les autres emplacements tarifés sont comptés), et seuil de rentabilité en nombre de parcelles occupées (et en %), comparé aux scénarios bas et central. Le tarif particulier est utilisé (pas le tarif pro).
- Les hypothèses (coûts, taux d'occupation, écarts) sont enregistrées avec le plan (clé `costs` de `data/galeries/<id>.json`, validée côté serveur) ; ce sont des estimations internes, jamais affichées au public. Code : `portail/galerie-plan.php` et `includes/gallery-map.php`.

## Appli visiteurs : appel dans la galerie, installation ; impression d'étiquettes

- **Page d'une galerie** : un bouton « 📱 Application visiteurs » apparaît dans l'en-tête dès qu'au moins un commerce membre a activé le module « Espace visiteur & fidélité » (plugin activé, à jour, essai ou licence valide). Un seul commerce : lien direct ; plusieurs : liste à déplier. Sans module actif chez aucun membre, rien n'est affiché (le module s'active commerce par commerce, depuis le portail ou la page Plugins).
- **Installation** : la page `/visiteur.php` propose « Installer l'appli », et la feuille commune `assets/pwa-save.js` s'ouvre dès l'arrivée sur téléphone avec les étapes du navigateur détecté (Safari, Chrome, Samsung, Firefox, navigateur intégré…). Manifeste : `visiteur-manifest.php` (nom et icône de la boutique, ouverture sur l'espace visiteur). Pas de mode hors ligne.
- **Étiquettes** : menu de l'administration → « Étiquettes (impression) » (`/admin/labels.php`) : on coche les pièces (filtre par nom ou référence), puis on choisit le format (planches A4, rouleaux) ; le bouton « 🏷️ Étiquettes » du catalogue, après avoir coché des pièces, mène à la même page.

## Espace visiteur et fidélité de la galerie (indépendant des boutiques)

La galerie a sa propre web app visiteurs et son propre programme de fidélité, sans rien exiger des boutiques membres (le plugin « Espace visiteur & fidélité » de chaque boutique reste un programme à part).

- **Réglages** : portail → galerie → « Fidélité visiteurs » (`portail/galerie-fidelite.php`, fonctionnalité `fidelite` des types de galerie, activée par défaut) : programme ouvert ou non, nom, points de bienvenue, points par lieu scanné, jusqu'à 5 récompenses (palier en points + libellé), message d'accueil, lieux de scan (un par ligne ; bouton pour reprendre les membres de la galerie).
- **Gagner des points** : chaque lieu (commerce, stand, entrée, buvette…) a un QR code secret (`gl_stand_code`) ; planche à imprimer depuis la page d'administration. Le visiteur le scanne avec l'appareil photo de son téléphone : points une fois par lieu et par jour (25 scans par jour au plus). Un lieu retiré de la liste ne rapporte plus rien.
- **Visiteur** : `galerie/visiteur.php?g=<galerie>` : inscription par e-mail (lien personnel, sans mot de passe, consentement obligatoire), points, barre de progression, échange contre une récompense (code à présenter à l'organisateur), lieux déjà scannés, déconnexion et effacement de ses données. Installable sur l'écran d'accueil : la feuille commune `assets/pwa-save.js` s'ouvre dès l'arrivée sur téléphone, avec les étapes du navigateur détecté, et `galerie/pwa-sw.php` rend l'appli installable sous Chrome/Android. Même chose pour l'espace exposant (`galerie/exposant.php`). Le QR scanné avant connexion est crédité à l'ouverture du lien reçu, si c'est dans le même navigateur.
- **Organisateur** : récompenses à remettre (« Remise ✓ » ou « Annuler » qui rend les points), membres (ajuster les points, supprimer, export CSV).
- **Page publique** : le bouton « 📱 Application visiteurs » de l'en-tête de la galerie pointe vers cette appli dès que le programme est ouvert et la galerie publiée ; sinon, il liste les espaces visiteurs des boutiques membres qui ont activé le module.
- Données : `data/galeries/<id>.fidelite.json` (jamais déployé, écriture sous verrou, limite de 8 inscriptions par heure et par connexion). Code : `includes/gallery-loyalty.php`. Hors périmètre : scan du QR dans l'appli elle-même (l'appareil photo du téléphone suffit), e-mail de relance des membres.

## Localisation : trouver un stand ou une boutique

La carte « Où trouver un stand ou une boutique » apparaît dans l'appli visiteurs (`galerie/visiteur.php`) dès que la galerie est publiée et qu'un plan ou un lieu est renseigné : elle reste disponible même si le programme de fidélité est fermé. Les positions viennent du plan de la galerie et des adresses des commerces membres (`includes/gallery-locate.php`).

- **Recherche et filtres** : recherche par nom d'emplacement, de commerce ou d'événement ; filtres *Tout / Commerces / Emplacements / Événement* ; chaque résultat indique son adresse, son stand et un bouton « Itinéraire » (application de cartes du téléphone).
- **Carte** : chargée seulement à l'ouverture du bloc (Leaflet 1.9.4 depuis cdnjs). Les zones, emplacements et boutiques du plan sont dessinés ; les zones passent en arrière-plan. Fonds **IGN Géoplateforme** sans clé, avec repli automatique sur Esri puis CARTO (les tuiles OpenStreetMap ne sont pas utilisées). L'adresse est complétée par le géocodage IGN (`data.geopf.fr`).
- **Me localiser** : demande la position de l'appareil et affiche la distance jusqu'à chaque lieu ; un refus ou un échec est expliqué sans bloquer la liste. La position n'est ni transmise au serveur ni enregistrée.
- **Appli exposant** : `galerie/exposant.php?g=<galerie>&t=<jeton>` reprend le même plan pour situer l'emplacement attribué, avec « Afficher le plan », « Itinéraire » et « Me localiser ». La page n'existe que par le lien personnel reçu par e-mail (`noindex`, `no-store`, `Referrer-Policy: no-referrer`), avec installateur (manifeste propre à la galerie) et lien d'annulation.

## Page « Nous trouver » de la boutique : carte IGN, itinéraire et carnet d'adresses

Chaque boutique a une page publique **`/nous-trouver.php`** (« Nous trouver » dans l'en-tête et le pied de page de tous les modèles). Un bloc d'actions compact figure aussi sur l'accueil, à côté des coordonnées : « Carte et itinéraire », « Enregistrer dans Google Maps » et « Ajouter au carnet d'adresses ». L'adresse est reprise de l'identité légale, puis de l'adresse publique de la boutique, puis du bloc contact ; une adresse d'exemple (« À compléter », etc.) est ignorée. Sans adresse, la page affiche seulement les contacts et la fiche `.vcf`, sans carte ni formulaire.

- **Carte** : Leaflet 1.9.4 (cdnjs), chargé seulement quand le bloc approche de l'écran. Fonds **IGN Géoplateforme** sans clé — Plan IGN v2 et photographies aériennes (`data.geopf.fr/wmts`) — et **aucune tuile OpenStreetMap**. L'adresse est géocodée par le service IGN (`/geocodage/search`) avec un cache local de 90 jours, puis repérée par une épingle.
- **Itinéraire** : champ de départ avec suggestions (adresse, ville ou code postal), bouton « Ma position » (géolocalisation du navigateur, jamais envoyée au serveur) et choix voiture / à pied. Le calcul passe par le service IGN Valhalla (`data.geopf.fr/navigation/itineraire`, resource `bdtopo-valhalla`) : tracé sur la carte, distance en mètres ou kilomètres et durée en minutes ou heures, puis lien « Ouvrir dans Google Maps » avec le point de départ.
- **Enregistrer l'adresse** : Google Maps n'expose pas d'adresse web qui ajoute un lieu aux favoris. Le bouton ouvre donc la fiche du lieu et la page indique d'appuyer sur « Enregistrer » ; sur iPhone, iPad et Mac, un bouton « Ouvrir dans Plans » apparaît (même limite, Apple ne fournit pas non plus de lien « ajouter aux favoris »).
- **Carnet d'adresses** : `carte-visite.php` renvoie une fiche **vCard 3.0** (`nom`, `adresse`, `téléphone`, `e-mail`, site, horaires, coordonnées si la carte les a trouvées) téléchargée en `.vcf`, importable dans les Contacts du téléphone, Outlook, Gmail ou l'application Contacts du Mac. La fiche répond 404 quand la boutique n'a ni adresse, ni téléphone, ni e-mail.
- **Fichiers** : `includes/locate.php` (données, boutons, section, vCard), `nous-trouver.php`, `carte-visite.php`, `assets/shop-locate.css`, `assets/shop-locate.js`, chargement dans `includes/functions.php`, liens dans les en-têtes/pieds de page et les accueils des modèles `vitrine`, `marche`, `feuille`, `librairie` et `mode`.
- **Tests locaux du 2026-11-03** : carte et tuiles IGN, bascule plan/orthophoto, suggestions de départ, itinéraire voiture (2,9 km / 10 min) et à pied (2,3 km / 34 min) sur l'adresse de test, lien Google Maps avec départ, `.vcf` avec `ADR` à 7 composants et coordonnées, préfixe multi-boutique (`/la-fripe-a-naty/carte-visite.php?lat=…&lng=…`), boutique sans adresse (message + 404 `.vcf`), aucun avertissement PHP. **Pas encore déployé en production.**

## Pass QR de confirmation (appli exposant)

Une fois sa demande **acceptée** par l'organisateur, l'exposant voit dans son espace un **QR code de confirmation** à présenter aux organisateurs ou à la sécurité du site.

- **Contenu** : le QR encode l'adresse de vérification `/galerie/exposant.php?g=<galerie>&p=<code>` ; le code est un HMAC propre à la demande (32 caractères hexadécimaux, `gb_pass_code()`), **distinct du jeton d'annulation** : communiquer le pass ne permet pas d'annuler la demande.
- **Dans l'appli** : QR affiché sous le statut, code en clair pour la saisie manuelle, copie, bouton « Afficher en grand » (plein écran, fermeture par Échap, croix ou clic à côté) et « Imprimer ». Le QR est dessiné localement (`assets/vendor/qrcode-generator.js`), sans service extérieur.
- **Contrôle** : la page `?p=<code>` affiche « Pass valide » ou « Pass non valide ». Un pass valide montre la galerie, le nom de l'exposant, l'emplacement, le statut « Confirmé », l'événement (titre, date, horaires, adresse) et un avertissement si l'événement est terminé. **Jamais d'e-mail ni de téléphone** ; la page ne permet aucune modification. Un bouton « Actualiser le contrôle » relit l'état au moment du scan, avec la date et l'heure du serveur.
- **Robustesse** : seules les demandes au statut `taken` produisent un pass ; une demande en attente, annulée ou expirée affiche « Pass non valide ». Les pages sont en `no-store` et `no-index`.
- Code : `includes/gallery-booking.php` (`gb_by_token`, `gb_by_pass`, `gb_pass_code`, `gb_pass_url`, `gb_app_url`) et `galerie/exposant.php`.

## Mobile d'abord (passe 1, front et administration)

Audit à 375 px de large du front de la boutique (accueil, boutique, fiche pièce, panier, compte, galeries, inscription) et de l'administration (catalogue, commandes, comptes, univers, promos, remises, frais de port, médiathèque, diaporamas, apparence). Le front était déjà bien adapté (menu burger, barre d'achat collée en bas de la fiche pièce) ; corrigé :

- **Socle** (`assets/style.css`, bloc « Mobile d'abord ») : champs à 16 px (sinon iOS zoome au focus), zones tactiles d'au moins 44 px (boutons, liens du pied de page et du fil d'Ariane, bouton menu, puce « IA ce mois-ci »), cases à cocher de 20 px, images et vidéos jamais plus larges que l'écran.
- **Catalogue** (`admin/catalog.php`) : la table de 1 178 px de large devient une liste de cartes (case, vignette, nom, prix, date, tri en tête) ; la barre d'actions groupées tient sur une ligne défilante (sur grand écran, elle passe à la ligne au lieu de déborder).
- **Tableaux** des comptes et des frais de port : défilement horizontal dans leur cadre. **Diaporamas** : cartes empilées. **Médiathèque** : filtres pleine largeur, grille de 150 px. **Apparence** : couleurs sur une colonne. **Univers** : puces de 40 px de haut.
- **Inscription** (`assets/pricing.css`) : les boutons radio masqués des formules élargissaient la page de 57 px.

Non couvert (à faire dans une passe suivante) : éditeur de plan des galeries, Studio, pages du portail de la plateforme, fiche d'édition d'une pièce dans le catalogue (formulaire déjà en une colonne, mais long) et caisse (POS).

## Catalogue : fiche d'édition en modale

Un clic sur le nom d'une pièce (`admin/catalog.php`) ouvre sa fiche dans une **modale** : fenêtre centrée à deux colonnes sur ordinateur (photos, bande de médias et liens à gauche ; champs à droite), **plein écran** sur mobile (médias repliés, pied de page collé en bas, 44 px de haut mini).

- **Quatre sections** : *Essentiel* (nom, univers, mention, prix affiché avec « Marché » et promo, visuel promo, description), *Détails* (matières, état, couleurs, marque, distributeur, code-barres, nature, sous-catégorie, taille, poids), *Prix* (prix minimum, négociation, fourchette estimée, prix du neuf ; pastille = offres à traiter) et *Vente* (mise en avant, masquer, stock, lot, canaux, marquer vendu, réassort, maintenue au catalogue). La section choisie est mémorisée.
- **Pied de page** : « Enregistrer » (reste sur la fiche, avec un bandeau de confirmation), « Enregistrer et fermer » (retour à la liste), « Supprimer » (confirmation). La touche Entrée enregistre. Un indicateur signale les modifications non enregistrées et la fermeture (✕, Échap, clic à côté) demande confirmation.
- **Navigation** : flèches ‹ › pour passer à la pièce précédente / suivante de la liste affichée (tri et recherche respectés). L'adresse `catalog.php#produit-<réf>` ouvre directement la fiche.
- **Rien n'a changé côté serveur** (mêmes champs, mêmes actions ; `save-product.php` ne fait que revenir à la liste pour « Enregistrer et fermer »). Les formulaires restent dans la page et sont déplacés dans la fenêtre à l'ouverture : l'IA, le prix, la promo, le code-barres et la nature fonctionnent comme avant. Un champ invalide dans une section masquée la rouvre.
- Correction au passage : le champ « Couleurs » apparaissait deux fois dans l'ancien formulaire (le second, vide, écrasait la valeur à l'enregistrement).
- Code : `assets/product-modal.js`, `assets/product-modal.css`.

## Boutique installable (web app) : incitation à l'installer

Chaque boutique est une web app installable (écran d'accueil du téléphone, plein écran, icône et nom de la boutique) :

- **Manifeste et icône** : `pwa-manifest.php` (nom, couleurs de l'apparence, ouverture sur l'accueil), `pwa-icon.php` (le logo carré de « Apparence » sur le fond de la boutique, à défaut l'initiale sur la couleur d'accent), `pwa-sw.php` (service worker minimal, rien n'est mis en cache : pas de mode hors ligne). Posés par `includes/header.php`, avec `apple-touch-icon` et `theme-color`.
- **Marche à suivre commune** (`assets/pwa-save.js`) : une feuille « Enregistrer … sur cet appareil » qui **adapte les étapes au navigateur détecté** — Safari iPhone/iPad (Partager → « Sur l'écran d'accueil »), Chrome Android (menu ⋮ → « Installer l'application »), Samsung Internet (☰ → « Ajouter page à » → « Écran d'accueil »), Firefox, Edge, Opera, Brave, Vivaldi, DuckDuckGo, Safari Mac (Fichier → Partager → « Ajouter au Dock »), et **navigateurs intégrés** de Facebook, Instagram, TikTok, Snapchat, LinkedIn, Pinterest, LINE, WeChat et X (ouvrir d'abord dans Safari ou Chrome, avec « Copier le lien »). Quand le navigateur propose sa propre invite (`beforeinstallprompt`), un bouton « Installer maintenant » la déclenche ; sinon la feuille explique le menu. « Plus tard » : 14 jours ; « Ne plus me proposer » : définitif ; installée : détectée. Feuille accessible (dialogue, Échap, fond cliquable, focus rendu au bouton).
- **Incitation de la boutique** (`assets/pwa-install.js`, uniquement sur téléphone / tablette, jamais si l'appli est déjà installée) : un bandeau « Ajoutez la boutique à votre écran d'accueil » apparaît **tout de suite après une arrivée par code QR (`?from=qr`, 1,2 s) ou par lien partagé (`?from=share`, 3,5 s)**, à partir de la 2<sup>e</sup> page vue (4 s), ou après 20 s sur une fiche pièce ; jamais dans le panier, le paiement, le compte ni l'administration, ni dans le navigateur intégré. « Ajouter » ouvre l'invite native ou la marche à suivre commune. « Plus tard » : 14 jours ; « × » : 90 jours.
- **Lien permanent** « 📲 Installer l'appli » en pied de page, sur toutes les mises en page.
- **Réglage** : Administration → Réglages du site → « Boutique installable (web app) » (activé par défaut ; décoché, plus de manifeste ni de bandeau).
- **Même marche à suivre dans les autres applis** : l'espace visiteur (`visiteur.php` et pack « Espace visiteur »), la galerie visiteurs (`galerie/visiteur.php`, manifeste + `galerie/pwa-sw.php`), l'espace exposant (`galerie/exposant.php`, manifeste + service worker) et le Studio (`studio.php`, service worker `studio-sw.php`) affichent la feuille commune dès l'arrivée sur téléphone, et proposent « Installer l'appli » / « Installer l'application ». Le shooting (`admin/shop-shooting.php`) a un bouton « 📲 Installer l'appli sur ce téléphone ». L'invite du navigateur ne s'affiche qu'en HTTPS (ou localhost).

## Panier : auto-complétion de l'adresse et localisation

Bloc « Destination » du panier (`cart.php`, script `assets/cart-address.js`) :

- **Auto-complétion** (France, Guadeloupe, Martinique, Guyane, Réunion, Mayotte) : la Base Adresse Nationale (api-adresse.data.gouv.fr, La Poste / Etalab, gratuite, sans clé) propose les adresses dès 3 caractères, au clavier (↑ ↓ Entrée Échap) comme au doigt. L'adresse choisie reste affichée (session, 160 caractères au plus) et son code postal fixe la zone tarifaire (Corse, outre-mer…) ; changer de pays ou de code postal l'efface.
- **📍 Me localiser** : demande la position de l'appareil, retrouve l'adresse (BAN en France, sinon OpenStreetMap / Nominatim, une requête par clic), puis renseigne le pays, le code postal et l'adresse, et recalcule les frais de livraison. Message clair si la localisation est refusée, indisponible, introuvable ou si la boutique ne livre pas dans ce pays. La position (latitude, longitude) n'est jamais envoyée à la boutique ni enregistrée : seuls l'adresse retrouvée et son code postal sont gardés. Le navigateur n'autorise la localisation qu'en HTTPS (ou localhost).
- L'adresse de livraison définitive reste saisie à l'étape de paiement Stripe (non préremplie) ; le panier ne sert qu'à choisir le pays, la zone et le mode de livraison.
- Au passage : la ligne d'article du panier s'affichait coupée sur mobile (prix et « Retirer » hors écran) ; elle passe en deux lignes sous 640 px.

## Panier : coordonnées (téléphone), conditions à accepter, compte client, pages légales

**Au panier** (`cart.php` → `checkout.php`), un bloc « Vos coordonnées » est obligatoire avant « Passer à la caisse » : e-mail et **téléphone** (contrôlés côté serveur : 8 à 15 chiffres, un numéro français saisi « 06 12 34 56 78 » est enregistré « +33612345678 »), nom facultatif, et la case **« J'ai lu et j'accepte les conditions générales de vente, les conditions d'utilisation et la politique de confidentialité »** (refus sans elle, messages sous les champs). L'e-mail est prérempli (verrouillé) sur la page Stripe. La commande conserve l'e-mail, le téléphone, la **date d'acceptation** et la **version des textes acceptés** (`orders.phone`, `terms_at`, `terms_version`) ; le téléphone s'affiche dans Administration → Commandes (lien d'appel) et sur la fiche du client (Comptes). Connecté·e, le client retrouve ses coordonnées préremplies.

**Compte client** (`compte.php`, `includes/customers.php`) : en plus de la connexion par réseau social, un compte se crée par **e-mail + mot de passe**, soit depuis « Créer mon compte » (nom, e-mail, téléphone, acceptation des conditions d'utilisation et de la confidentialité), soit en cochant « Créer mon compte client » au panier. Dans les deux cas, l'adresse est **vérifiée par un lien** (valable 24 h, à usage unique, empreinte SHA-256 en base) qui permet de choisir le mot de passe (8 caractères au moins) : sans clic, aucun compte n'existe. Même lien pour « Mot de passe oublié ». Le compte (rôle prospect, puis client à la première commande payée) permet de suivre ses commandes, modifier nom et téléphone, changer de mot de passe et **supprimer son compte** (un prospect est effacé ; un client perd accès et coordonnées, ses commandes restent pour la comptabilité). Garde-fous : 8 essais de connexion par quart d'heure et par adresse/connexion, 5 liens par heure, mêmes réponses que l'adresse existe ou non, jamais de compte client sur l'adresse d'un membre de l'équipe (leur mot de passe d'administration n'est pas touché). Le paiement reste possible sans compte.

**Pages légales** (`legal.php?page=cgv|cgu|confidentialite|mentions`, liens en pied de page de toutes les mises en page) : conditions générales de vente, conditions d'utilisation, politique de confidentialité et mentions légales. Chaque texte part d'un **modèle** rempli avec l'identité du vendeur ; Administration → **Pages légales** (`admin/legal.php`) permet de saisir cette identité (nom, SIRET, adresse, e-mail, TVA, hébergeur, médiateur, délai de rétractation), de modifier chaque texte (mise en forme : « ## Titre », paragraphes, listes « - »), de rétablir le modèle et de **valider** l'ensemble : l'écran indique « À valider » tant que ce n'est pas fait ou dès qu'un texte ou l'identité change. **Ces modèles ne sont pas un avis juridique** : à compléter (les « [À COMPLÉTER] ») et à faire relire avant la mise en ligne.

**Livraison à une autre personne** : au panier, la case « La livraison est pour une autre personne » ajoute le **nom** et le **téléphone du destinataire** (exigés si la case est cochée, contrôlés côté serveur). Ils sont conservés avec la commande (`orders.ship_name`, `ship_phone`) et affichés dans Administration → Commandes (« 🎁 Livrer à … », lien d'appel, mention « différent du client »). L'adresse de livraison elle-même se saisit à l'étape de paiement Stripe et peut toujours différer de celle du client ; les coordonnées du client (e-mail, téléphone) restent celles de la facturation et du compte.

**Adresse de livraison saisie au panier** (bloc « Destination » de `cart.php`) : pays, **adresse (numéro et rue)**, **complément** (bâtiment, étage, digicode…), **code postal** et **ville**. La recherche d'adresse (« Rechercher mon adresse », Base Adresse Nationale) et « 📍 Me localiser » remplissent tous ces champs, qui restent modifiables à la main (autres pays compris). Le code postal fixe la zone tarifaire. À « Passer à la caisse », l'adresse affichée part avec le formulaire de paiement (même sans avoir cliqué « Actualiser ») et est **contrôlée côté serveur** : adresse, ville et code postal (5 chiffres en France) sont exigés dès que la livraison n'est pas un retrait sur place. Le paiement Stripe est **prérempli** avec cette adresse (client Stripe portant l'adresse et le nom du destinataire, modifiable par le client) ; si la création du client échoue, le paiement continue sans préremplissage. L'adresse saisie est conservée avec la commande (`orders.declared_address`) et sert de repli si Stripe n'en renvoie pas ; l'adresse finale confirmée chez Stripe reste prioritaire.

## Recrutement : emplois, primo-aidants et bénévoles de vide-grenier

Module autonome pour recruter sur trois pistes, sans toucher au module Équipe existant :

- **Trois pistes** : *Emploi* (salarié, freelance, prestation, stage), *Primo-aidant* (amis et famille qui aident sur le design, le fonctionnel, le financement ou les tests) et *Bénévole* (tenue d'un stand lors d'un vide-grenier ou de puces). Chaque piste a ses domaines, ses contrats et son formulaire.
- **Besoins publiés** (`portail/recrutement/`) : titre, piste, contrat, domaines, missions, profil recherché, rémunération, lieu, télétravail, nombre de places, date limite et, pour les bénévoles, **le vide-grenier concerné** (choisi parmi les événements de galerie à venir, types `vide-grenier` et `puces`). Chaque besoin reçoit un **code court** et un lien public ; un besoin échu ou fermé cesse d'accepter des candidatures.
- **Page publique** (`/portail/recrutement/postuler.php`) : accueil avec les trois pistes et les besoins ouverts, page d'un besoin (`?offre=CODE`), ou candidature spontanée par piste (`?piste=emploi|primo|benevole`). Le formulaire s'adapte à la piste (domaines, disponibilité, expérience, prétentions, mobilité, date de l'événement). Garde-fous : CSRF, champ piège, 3 envois par heure et par session, refus des doublons sur le même besoin pendant 30 jours, consentement obligatoire, notification à l'adresse réglée dans le portail.
- **Suivi des candidatures** : statuts reçue → contactée → entretien → retenue → intégrée (écartée à tout moment), notes horodatées, comptage par besoin, export CSV, suppression.
- **Pont vers Équipe** : un primo-aidant retenu peut être envoyé en un clic dans le module Équipe (`includes/equipe.php`), qui reste la source de vérité pour les conseillers, testeurs et partenaires financiers (catégorie et domaines traduits).
- **Onglet Bénévoles** : la liste des vide-greniers à venir avec, pour chacun, les missions créées et les bénévoles confirmés (statut retenue ou intégrée) ; création rapide d'un besoin rattaché à l'événement.
- **Onglet Partage** : liens publics par piste et par besoin (bouton « Copier »), réglages (ouverture des candidatures spontanées, adresse de notification, texte d'introduction).
- **Stockage** : fichiers JSON dans `data/recrutement/` (`besoins/`, `candidatures/`, `settings.json`), jamais servis en HTTP, comme le reste du portail ; écritures atomiques. Aucune table SQL, aucune migration.
- **Accès** : entrée « Recrutement » dans le menu du portail (groupe Pilotage), avec pastille des candidatures à traiter.
- **Code** : `includes/recrutement.php`, `portail/recrutement/index.php`, `portail/recrutement/action.php`, `portail/recrutement/postuler.php` ; tests `tests/RecrutementTest.php`.
