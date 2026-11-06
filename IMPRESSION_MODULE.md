# Module Impression — commander affiches, cartes de visite, plans, goodies

Commande d'impression auprès de fournisseurs, **par API** quand ils en ont une :
comparaison des prix, commande, suivi du colis. Pour un imprimeur sans API
(imprimeur local, site classique), le module prépare le brief de devis et vous
suivez la commande à la main.

- **Accès** : *Administration → Impression* (`/admin/impression/`), même mot de passe que le reste de l'admin.

## Pour démarrer (15 minutes)

1. **Fournisseurs & clés API** : créez un compte gratuit chez [Gelato](https://dashboard.gelato.com/), [Prodigi](https://dashboard.prodigi.com/) et/ou [Cloudprinter](https://account.cloudprinter.com/registration), créez-y une clé API, collez-la dans le module. Prodigi et Cloudprinter ont **deux clés** : *sandbox* (gratuite, rien n'est produit) et *live*.
2. **Catalogue & références** : pour chaque produit, collez la **référence du fournisseur** (`productUid` Gelato, `SKU` Prodigi, référence produit Cloudprinter) copiée depuis leur catalogue. Pour Cloudprinter, le bouton *Voir les produits Cloudprinter* (page Fournisseurs) liste les références activées sur votre compte. Seule une référence est préchargée (voir plus bas) ; **le module ne devine aucun identifiant produit**.
3. **Nouvelle commande** : produit, quantité, fichier prêt à imprimer, adresse de livraison.
4. **Comparer les fournisseurs** → choisir une offre → **Commander**. Rafraîchir le statut pour récupérer le suivi colis.

## Sécurité financière

Commander par API engage de l'argent réel. Garde-fous :

| Garde-fou | Effet |
|---|---|
| **Mode TEST par défaut** | Gelato : commande de type `draft` (enregistrée, jamais produite ni facturée). Prodigi : hôte *sandbox* + clé sandbox (« sans coût » selon leur documentation). Cloudprinter : clé d'une interface API en mode *Sandbox* (commandes gratuites, jamais envoyées à un imprimeur). |
| **Mode LIVE explicite** | Bascule dans le tableau de bord, avec confirmation. Le bandeau « MODE TEST / MODE LIVE » reste visible partout. |
| **Plafond par commande** | 150 € par défaut, modifiable. Une offre au-dessus est refusée (faute de frappe sur la quantité). |
| **Confirmation avant envoi** | Fournisseur, montant, livraison et mode rappelés. |
| **Pas de doublon** | Verrou atomique (double clic), commande déjà passée non renvoyable, clé `idempotencyKey` chez Prodigi. Un envoi interrompu (> 5 min) passe en « échec » avec la consigne de **vérifier chez le fournisseur avant de recommander** (Gelato n'offre pas d'idempotence). |
| **Fichier joignable** | En live, refus si `SITE_URL` n'est pas une adresse publique en https (le fournisseur télécharge le fichier par URL). |

## Fournisseurs

Annuaire de départ (vérifié sur leurs sites et documentations publiques, nov. 2026) :

| Fournisseur | Type | Remarques |
|---|---|---|
| **Gelato** | API connectée | API v4 : devis, commande, suivi, annulation. Production locale dans de nombreux pays. Affiches, cartes de visite, flyers, mugs, textile. |
| **Prodigi** | API connectée | API v4 : devis, commande, suivi, annulation. Affiches/tirages d'art, cadres, coques… Sandbox gratuit. |
| **Cloudprinter** | API connectée | Core API 1.0 : devis, commande, suivi, annulation. Impression locale en France annoncée. Catalogue activé compte par compte, surtout orienté livres et produits imprimés : **vérifiez qu'il propose des affiches / cartes de visite** (bouton *Voir les produits*). Prix HT + TVA à part (le module affiche le TTC), devis valables 48 h. **Abonnement mensuel** dès l'enregistrement de la carte pour passer en live. |
| Print.com | API existante | Pays-Bas, livraison Europe, marque blanche. Connecteur non fourni. |
| Helloprint Connect | API existante | Réservé aux revendeurs vérifiés (inscription requise). Connecteur non fourni. |
| Exaprint | API existante (sur demande) | Imprimerie en ligne française. API **ExaConnect** gratuite, mais accès sur demande ; connecteur non fourni (voir plus bas). Commande sur leur site et suivi à la main en attendant. |
| Pixartprinting | sans API | Orienté professionnels. Aucune API publique vérifiée. |

Ajoutez vos imprimeurs locaux dans *Fournisseurs & clés API*. Ajouter un
connecteur (Cloudprinter, Print.com…) = une classe dans `includes/impression.php`
(`extends PrProvider`, 4 méthodes : `quote`, `order`, `status`, `cancel`) + une
ligne dans `pr_provider()`.

### Exaprint : pourquoi pas de connecteur

Exaprint propose bien une API gratuite, **ExaConnect** (jeton d'accès, création de
commande multi-lignes, envoi des fichiers, suivi, annulation, catalogue de produits
courants) : [présentation](https://www.exaprint.fr/exaconnect/), [documentation](https://connect.exaprint.com/documentation/).
Mais l'accès est **sur demande** (identifiants à demander via un formulaire), et leur
référence d'API est un visualiseur qui ne s'affiche pas sans navigateur : les routes et
champs exacts n'ont pas pu être lus. Plutôt que d'en deviner, **aucun connecteur n'est
fourni**. Leur page n'annonce pas non plus de calcul de devis par API.
Pour le brancher : demandez l'accès ExaConnect, puis ajoutez une classe `PrExaprint`
(`extends PrProvider`) d'après leur spécification.

## Imprimeurs sans API

La page d'une commande génère un **brief** (produit, quantité, format, adresse,
lien du fichier) avec un lien « Écrire » pré-rempli. Notez « J'ai demandé un
devis », saisissez le **prix reçu** pour le comparer aux offres API, puis
« Marquer commandée » ; le statut se met alors à jour à la main.

## Ce qui est vérifié, et ce qui ne l'est pas

**Testé automatiquement** (`php scripts/test-impression.php`, 242 contrôles) et
**dans un navigateur** (parcours complet) — mais avec un **faux fournisseur**
reprenant la forme des exemples de la documentation : aucun appel réel n'a été
fait faute de clés.

**À valider à votre première commande test** (formats repris de la doc publique) :

- *Gelato* : devis `POST /v4/orders:quote`, commande `POST /v4/orders` (`orderType: draft|order`), annulation `POST /v4/orders/{id}:cancel`, statuts : **confirmés dans la doc**. Le champ `price` d'une ligne de devis est interprété comme le **prix de la ligne, quantité comprise** : la doc ne le dit pas explicitement. Le montant `receipts[].total` renvoyé à la commande (« Montant confirmé ») permet de le vérifier.
- *Prodigi* : commande `POST /v4.0/Orders`, liste, structure des statuts et du suivi : **confirmés dans leur collection Postman**. Devis `POST /v4.0/quotes`, lecture `GET /v4.0/Orders/{id}` et annulation `POST /v4.0/Orders/{id}/actions/cancel` : conformes à la doc de référence mais **non revérifiés en ligne** (la page de référence ne s'affiche pas sans JavaScript). Le mode sandbox les exerce gratuitement.
- *Cloudprinter* : devis `/orders/quote`, commande `/orders/add`, annulation `/orders/cancel`, lecture `/orders/info`, produits `/products`, états de commande, mode sandbox : **tous confirmés dans leur documentation**. Points à valider à la première commande sandbox : (1) l'état `45` (fichiers remis à l'imprimeur) est affiché « En production » et `100` « Expédiée » (Cloudprinter ne distingue pas expédiée / livrée) ; (2) le suivi colis est un simple texte (`items[].tracking`), sans lien cliquable ; (3) un seul fichier par produit (type `product`) : les produits à couverture + intérieur (livres) ne sont pas gérés.
- Une seule référence produit est préchargée : SKU Prodigi `GLOBAL-FAP-16x24` (affiche 40 × 60 cm, papier mat 200 g), tiré de leur documentation. Toutes les autres sont à renseigner. Une référence erronée est refusée par le fournisseur dès « Comparer ».
- Prix et délais : estimations du fournisseur au moment du devis. Prodigi ne donne pas de délai dans ses devis (affiché « — »).
- Les prix peuvent être HT ou TTC selon le fournisseur et le pays : vérifiez sur la première commande.

## Fichiers

```
includes/impression.php          logique, schéma SQLite, connecteurs Gelato et Prodigi, annuaire
includes/impression-layout.php   gabarits admin
admin/impression/*.php           commandes, nouvelle commande, détail, catalogue, fournisseurs
assets/impression.css            styles (complète assets/buvette.css, dont il réutilise les composants)
scripts/test-impression.php      tests (base jetable, HTTP simulé)
uploads/impression/              fichiers à imprimer (noms aléatoires, non versionné)
.secrets/impression_*.key        clés API (non versionné, non déployé)
```

Tables préfixées `pr_`, créées automatiquement au premier accès (`pr_boot()`) :
rien à migrer ni à lancer au déploiement. PHP 8.0+, SQLite et cURL, comme le site.

## Limites connues

- Un fichier = un produit par commande (pas de panier multi-lignes).
- Les fichiers à imprimer sont servis par une URL publique (nom aléatoire de 32 caractères) : c'est ainsi que Gelato et Prodigi les téléchargent. Ne déposez pas de document confidentiel.
- Le contrôle de résolution ne couvre que les images (PNG/JPEG/TIFF), pas le contenu d'un PDF.
- Pas de webhooks (Cloudprinter propose des « CloudSignal » ; Gelato aussi) : le statut se rafraîchit avec « Actualiser le statut ».
- La TVA, la facturation et le paiement se gèrent chez le fournisseur (carte enregistrée sur leur compte), pas dans ce module.
- Sur un hébergement mutualisé, vérifier `upload_max_filesize` : de gros PDF peuvent dépasser la limite PHP par défaut.
