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

## Prix du marché : le même article, neuf et d'occasion

Le bouton **Marché** à côté du champ Prix (catalogue, relectures du Studio et de l'ajout rapide) lance une vraie recherche
web (`admin/price-research.php`, `includes/price-research.php`) : Gemini avec l'outil « Google Search » cherche le prix du
MÊME article — ou du plus proche —, neuf et d'occasion, en France, en s'aidant des photos de la pièce pour reconnaître marque
et modèle, de son nom, de ses matières, de sa nature, de sa taille et de son état. Le résultat s'affiche sous le champ :
l'article reconnu, un tableau **votre catalogue** (pièces similaires déjà enregistrées : fourchette et médiane) /
**neuf (web)** / **occasion (web)**, un **prix conseillé** pour CET exemplaire dans son état avec sa justification, la
fiabilité de la recherche (« faible » quand peu de prix comparables existent : l'IA doit le dire plutôt qu'inventer) et les
sources et recherches effectuées. « Utiliser » écrit le prix dans le champ, sans rien enregistrer. Une recherche prend 20 à
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

