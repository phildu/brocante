# Module Buvette — vide-grenier

Gestion de la buvette / restauration d'un vide-grenier par une association :
carte et prix, recettes, fournitures, quantités à acheter, planning des
bénévoles et check-list du jour J.

- **C'est une extension de galerie** (pour l'organisateur d'un vide-grenier, pas pour les exposants) : elle se trouve dans l'espace de la
  galerie, onglet **Extensions**, où on l'essaie gratuitement puis on s'abonne ; une fois active, un bouton **Buvette** apparaît dans son menu
  (`/portail/galerie-buvette.php`). Proposée d'office aux galeries de type « vide-grenier » ; prix, essai et types concernés se règlent dans le
  portail → *Tarifs des extensions de galerie*, et le mode par type dans *Types de galerie*.
- **Responsable** : l'administrateur de la galerie (ou l'exploitant du portail).
- **Bénévoles** : `/galerie/<galerie>/buvette/`, depuis un téléphone, avec un **code partagé** (à définir dans le tableau de bord). Lecture seule, sauf pour cocher la check-list. Un code ne vaut que pour sa galerie.
- **Données** : une base SQLite par galerie (`data/galeries/<galerie>.db`, tables `bv_…`, créée au premier accès, supprimée avec la galerie), jamais partagée avec une autre galerie ni avec une boutique. Les pages du module (`admin/buvette/`, `buvette/`) ne sont plus servies seules : `/admin/buvette/…` et `/buvette/…` sans galerie renvoient une page introuvable. Les adresses écrites en dur dans le module sont réécrites vers la galerie (`bv_url_map()`).
- **Tests** : `php scripts/test-buvette.php` (module) et `php scripts/test-buvette-galerie.php` (galerie : base, adresses, accès).

## Pour démarrer

1. Dans l'espace de la galerie, activer l'extension (onglet *Extensions*), puis ouvrir *Buvette*.
2. Cliquer sur **« Charger la buvette d'exemple »** (café, boissons, sandwichs, hot-dogs, crêpes, gâteaux, avec recettes, planning et check-list) et tout modifier, **ou** créer un événement et saisir ses propres données.
3. Définir le **code d'accès bénévoles** dans le tableau de bord, puis leur envoyer le lien et le code.

> Les prix, quantités et allergènes de l'exemple sont **indicatifs**. Vérifiez
> les allergènes sur les étiquettes des produits réellement achetés.

## Ce que fait chaque onglet

| Onglet | Rôle |
|---|---|
| **Tableau de bord** | Événements (créer, dupliquer l'édition précédente, activer), chiffres clés, alertes « à traiter », code d'accès bénévoles. |
| **Carte & prix** | Articles vendus, prix, catégories, **14 allergènes** réglementaires, coût de revient et marge par portion. *Affiche de la carte* imprimable. |
| **Recettes** | Par article : quantités **par portion** (en g, L, pièces…) et étapes de préparation. Alimente le coût de revient et la liste de courses. |
| **Fournitures** | Ingrédients, consommables (gobelets…) et matériel. On saisit un **lot** (« paquet de 1 kg à 10,90 € ») ; le prix à l'unité s'en déduit. Suivi du stock. |
| **Prévisionnel & bilan** | Portions prévues par article → CA et marge prévus. Après l'événement, saisir les quantités **vendues** → bilan réel. |
| **Courses** | Besoins = recettes × prévisions (+ marge de sécurité) + besoins manuels (tables, glacières…) − stock, arrondis au **lot entier**. Imprimable. |
| **Bénévoles & planning** | Annuaire (coordonnées visibles du responsable seul), créneaux (poste, horaires, effectif), affectations. Signale les trous et les doubles réservations. Lien vers la **charte du bénévole occasionnel** (`charte.php`, fiche A4 à imprimer et faire signer aux non-adhérents, préremplie avec l'association et l'événement). |
| **Check-list** | Liste type, personnalisable, avec progression : **démarches officielles** (déclaration de vente au déballage Cerfa 13939*01, occupation du domaine public, assurance, sécurité, règlement intérieur, registre des exposants coté et paraphé, dépôt du registre sous 8 jours), puis autorisation de buvette, hygiène, allergènes, installation, rangement, bilan. Ce sont des rappels : les règles exactes dépendent de la commune. |

Un seul événement est « en cours » à la fois : les onglets Prévisionnel, Courses, Bénévoles et Check-list travaillent sur lui. La carte, les recettes et les fournitures sont communes à tous les événements.

## Ce que voient les bénévoles

Carte avec prix et allergènes, recettes (quantités par portion, préparation),
planning (prénoms uniquement, avec « c'est vous » après avoir choisi son
prénom) et check-list à cocher. Ils ne voient **ni prix d'achat, ni marges, ni
téléphones / e-mails**. Après 5 essais de code ratés, l'accès est bloqué
15 minutes pour l'adresse IP concernée.

## Installation et déploiement

Rien à installer ni à migrer : les tables (préfixe `bv_`) se créent toutes
seules au premier accès (`bv_boot()` dans `includes/buvette.php`). Elles
n'affectent pas les tables existantes de la boutique.

`deploy-brocante.sh` n'envoie jamais `brocante.db` (sauf `--with-db`) : les
données de la buvette saisies en ligne ne sont donc pas écrasées. Avec
`--with-db`, elles sont remplacées par celles de votre base locale, comme le
reste du site.

PHP 8.0 ou plus, SQLite (déjà requis par le site).

## Fichiers

```
includes/buvette.php          logique métier, schéma SQLite, données d'exemple
includes/buvette-layout.php   gabarits admin et bénévoles
admin/buvette/*.php           pages du responsable
buvette/*.php                 espace bénévoles
assets/buvette.css            styles (reprend les variables de style.css, thème sombre inclus)
scripts/test-buvette.php      tests de la logique métier
```

## Tests

```bash
php scripts/test-buvette.php
```

Tourne sur une base jetable (variable `BROCANTE_DB`) : ne touche jamais à
`brocante.db`. Couvre les calculs (coûts de revient, besoins, arrondi au lot,
marge de sécurité, stock), le planning (conflits d'horaires), la duplication
d'événement, la suppression en cascade et le contrôle d'accès bénévole.

## Limites connues

- Le coût de revient et la marge sont **théoriques** (quantités des recettes × prix d'achat) : ils ne tiennent pas compte de la casse ni des invendus.
- Ce n'est **pas une caisse enregistreuse** : les quantités vendues se saisissent après coup, en totaux par article.
- Les rappels réglementaires de la check-list (autorisation de buvette, hygiène, allergènes) sont des **pense-bêtes**, pas un avis juridique : la mairie reste l'interlocuteur pour les règles exactes de la commune.
- Le code bénévole est un secret partagé, adapté à des données peu sensibles (carte, planning) ; ne l'utilisez pas pour y mettre d'autres informations.
