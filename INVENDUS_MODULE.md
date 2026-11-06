# Module « Invendus & dons » d'un vide-grenier

Permet de donner une seconde vie aux invendus d'un vide-grenier : les exposants
proposent des dons sur le site, un point de collecte ouvre en fin de journée,
les objets y sont amenés (par leur propriétaire ou par une tournée à vélo cargo),
les visiteurs se servent gratuitement, et le reliquat part vers une donnerie.

## Déroulé

| Qui | Quand | Quoi |
|---|---|---|
| Organisation (admin) | avant | Crée l'**édition** (date, lieu, heure d'ouverture du point), enregistre les **donneries**, publie. |
| Exposants | avant / pendant | Proposent un objet sur `/dons-proposer.php` (apporté par eux, ou à ramasser à leur stand). Reçoivent un lien de suivi privé. |
| Exposants | fin de journée | Confirment « c'est un invendu » (ou retirent l'objet s'il est vendu) depuis leur lien. |
| Organisation | fin de journée | Crée des **tournées** (bénévole + vélo cargo + capacité), leur affecte les ramassages. |
| Bénévole de tournée | tournée | Lien secret : « Récupéré » / « Plus dispo » par stand, jauge de chargement, « Tournée terminée ». |
| Bénévoles du point | collecte | Lien secret : réception, « Pris » quand un visiteur emporte l'objet, dépôts spontanés. |
| Visiteurs | collecte | `/dons.php` : voient en direct ce qui est au point, gratuitement. |
| Organisation | après | Ferme le point, remet le **reliquat** à une donnerie → **bordereau** imprimable. Export CSV. |

Le point s'ouvre et se ferme seul aux heures prévues ; l'admin peut aussi l'ouvrir/le fermer à la main.

## Cycle de vie d'un objet

`proposé → récupéré (en route) → au point → pris par un visiteur | remis à une donnerie`, ou `retiré` (vendu, repris, abîmé).
Chaque changement est consigné (qui, quand) ; les retours en arrière existent pour rattraper une fausse manipulation.
Seuls les objets « au point » apparaissent sur la page publique.

## Accès (pas de comptes utilisateurs sur ce site)

- **Admin** : espace `/admin/` existant, entrée « Invendus & dons » (mot de passe admin + jeton CSRF sur chaque action).
- **Exposant** : lien de suivi privé (`/dons-suivi.php?t=…`), partagé par toutes ses propositions faites depuis le même appareil.
- **Bénévole de tournée** / **bénévoles du point** : liens secrets générés par l'admin (onglets *Tournées* et *Aperçu*).
  Ces pages ne sont ni indexées, ni mises en cache, et n'envoient pas le jeton en `Referer`.
- Les coordonnées des exposants ne sont **jamais** publiques ; seuls l'admin et les bénévoles (téléphone) les voient.

## Fichiers

- `includes/invendus.php` : toute la logique (schéma, phases, règles de transition, tournées, remises).
- `includes/invendus-layout.php`, `includes/invendus-tabs/*` : gabarits admin et pages terrain.
- Public : `dons.php`, `dons-proposer.php`, `dons-suivi.php`. Terrain : `dons-tournee.php`, `dons-point.php`.
- Admin : `admin/invendus*.php` (liste, hub d'édition à onglets, fiche, donneries, bordereau, export, actions).
- `assets/dons.css`, `scripts/test-invendus.php`.

## Déploiement

Le déploiement n'envoie ni `brocante.db` ni les `*.sql` : les tables `unsold_*` sont donc **créées automatiquement**
au premier chargement d'une page du module (`CREATE TABLE IF NOT EXISTS`). Elles sont aussi dans `schema.sql`
pour les installations neuves — garder `unsold_schema_sql()` et `schema.sql` synchronisés.
Les photos des propositions sont stockées dans `uploads/don-*.jpg`.

## Tests

```bash
php scripts/test-invendus.php   # logique métier, sur une base SQLite jetable (brocante.db n'est jamais touchée)
```

## Limites connues / pistes

- Pas d'e-mail ni de SMS automatiques (le site n'a pas d'envoi de messages) : l'admin transmet les liens.
- Le volume d'un objet se compte en 3 tailles (petit/moyen/encombrant) pour la capacité du vélo cargo ; pas d'optimisation d'itinéraire (ordre naturel des numéros d'emplacement).
- Anti-spam du formulaire public : jeton CSRF, champ piège, délai minimal, plafonds par session et par heure.
