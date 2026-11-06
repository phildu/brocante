# Extension « Annulation & remboursements » d'un vide-grenier

> **Extension de galerie** (`annulation`, `includes/gallery-modules.php`), proposée d'office aux galeries de type « vide-grenier » : l'organisateur
> l'active (essai gratuit de 14 jours, puis abonnement) depuis l'onglet **Extensions** de son espace ; un bouton **Annulation** apparaît dans son
> menu (`/portail/galerie-annulation.php`). Prix, essai et types concernés : portail → *Tarifs des extensions de galerie* ; mode par type :
> *Types de galerie*. Aucun prix n'est imposé.

Annule l'événement de la galerie (météo, force majeure, décision de l'organisateur) et rembourse les exposants **en totalité ou en partie**.

> **Le site n'encaisse pas les emplacements.** Le registre sert à suivre les virements ou chèques que l'organisateur fait lui-même ; rien n'est
> versé automatiquement.

## Déroulé

1. **Annuler** (page de l'extension) : cause, motif, puis part du tarif rendue — *selon les conditions de vente* (assistant, onglet Règlements),
   *tout* (100 %), *une partie* (pourcentage libre) ou *rien*. Un **aperçu** montre le montant de chaque exposant et le total, recalculés en
   direct, avant confirmation. Délai et moyen de remboursement annoncés ; alerte e-mail immédiate (facultative).
2. L'annulation **ferme les demandes d'emplacement**, s'affiche sur la page publique et dans l'espace exposant, et crée **une ligne par exposant**.
   Une demande encore en attente n'a rien payé : prévenue, sans remboursement.
3. **Ajuster exposant par exposant** : montant libre (frais retenus, geste commercial, remboursement partiel individuel). Plafonné au tarif payé ;
   0 € = « Sans remboursement » ; refusé sur une ligne déjà remboursée (la repasser d'abord en « À rembourser ») et sur une demande en attente.
   Pour un exposant sans tarif enregistré, le montant payé se saisit librement. L'ajustement remet son alerte à zéro (« à renvoyer »).
4. **Prévenir** : un exposant, ceux dont l'information n'est pas à jour, ou tous. Les administrateurs reçoivent un récapitulatif.
5. **Suivre** : « À rembourser » → « Remboursé » (avec référence du virement), indicateurs et barre d'avancement, **export CSV** (tout le registre,
   ou seulement les virements restant à faire), journal chaîné des opérations.
6. **Réactiver** l'événement en cas d'erreur : les lignes de remboursement sont supprimées (la confirmation le rappelle).

## Si l'extension s'arrête

Lancer une **nouvelle** annulation exige l'extension active. Le suivi d'un événement **déjà annulé** (registre, ajustement, alertes, export,
réactivation) reste ouvert, et le bouton du menu reste visible : les exposants attendent leur argent.

## Fichiers

```
includes/gallery-cancellation.php   logique : barème, aperçu (gcn_preview), annulation, ajustement (gcn_set_amount), alertes, CSV
includes/gallery-modules.php        déclaration de l'extension (clé « annulation »)
portail/galerie-annulation.php      page : annuler, aperçu, registre, export, journal
portail/galerie-evenement.php       page Événement : carte qui renvoie vers l'extension (plus de formulaire d'annulation en dur)
scripts/test-annulation-galerie.php tests
```

Données : l'annulation est écrite dans l'événement (`data/galeries/<id>.json`), les lignes de remboursement dans les demandes
(`data/galeries/<id>.slots.json`, clé `refund`) — rien à migrer, aucune table.

## Tests

```bash
php scripts/test-annulation-galerie.php   # 130 contrôles, dossier temporaire
```

## Limites connues

- Pas de virement automatique ni de remboursement Stripe : suivi uniquement.
- Les montants sont plafonnés au **tarif enregistré** de la demande ; pour un exposant sans tarif, la saisie est libre (jusqu'à 10 000 €).
- Seules les demandes attribuées sont remboursées ; une demande en attente n'a rien payé.
