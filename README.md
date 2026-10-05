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
| http://brocenstock.test                     | le commerce par défaut (`.tenant`, sinon Petit Chalet) |

Le sous-domaine est l'identifiant du commerce (nom de son dossier dans
`tenants/`) ; un sous-domaine inconnu affiche le commerce par défaut.

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

## Univers (rayons) modifiables, taille et poids estimés par l'IA

**Univers.** Les univers d'une boutique (filtres de « La boutique », accueil, champ « Univers » des fiches) venaient
uniquement de `tenants/<slug>/tenant.php` : une boutique créée sur un modèle de boulangerie gardait « Pains,
Viennoiseries… » même pour vendre des vêtements, et l'IA, qui choisit l'univers parmi cette liste, ne pouvait que se
tromper. La page **Univers** de l'administration (`admin/universes.php`, `includes/universes.php`) les rend modifiables
sans toucher au serveur : renommer (les pièces restent rattachées, la clé est conservée), ajouter, réordonner, retirer,
choisir le pictogramme. La liste enregistrée (table `settings`, clé `universes`) remplace celle du fichier ;
« Rétablir les univers d'origine » l'efface. **Proposer des univers (IA)** en suggère 4 à 8 d'après le nom de la
boutique, son accroche, les natures et les noms des pièces en vente. **Reclasser par l'IA** (pièces sans univers valide,
ou toutes) rejoue le choix de l'univers pièce par pièce, enregistré aussitôt, arrêtable. Les prompts de l'IA citent
désormais les univers enregistrés (`ai_shop_examples()`), et l'IA peut ne choisir aucun univers s'ils ne conviennent
pas (message : « adaptez-les dans Univers »).

**Taille et poids.** Boutons « ↻ IA » sur Taille et Poids (catalogue, relectures du Studio et de l'ajout rapide),
champs inclus dans « Tout (re)générer » et dans la génération de fiche (Studio, lot, ajout rapide) : taille lue sur
l'étiquette d'un vêtement ou dimensions estimées d'un objet (« env. 22 cm de haut, Ø 11 cm »), poids estimé en grammes
d'après la nature, les matières et la taille apparente (`weight_grams` pour les frais de port ; `weight_text` « env.
900 g » écrit pour la fiche). Ce sont des **estimations** : l'interface le rappelle pour le poids (à peser).

## Nature du produit et sous-catégories, détectées par l'IA

Indépendante de l'« univers » du commerce (ses rayons), la **nature** dit ce que l'objet est : 15 natures et 119
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
enregistrés apparaissent en pastilles dessous, un clic les remet dans le champ, « × » les supprime.
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

