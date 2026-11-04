# Fiche de capture — Encaisser les paiements de sa boutique

Tutoriel : `/aide/tutoriel.php?slug=encaisser`

Statut global : **à faire**. Aucune image n'est fournie.

| Fichier attendu | Statut | URL exacte | Compte / jeu de données | Action | Cadrage | Texte de remplacement |
|---|---|---|---|---|---|---|
| `encaisser-01-reglages.png` | a-faire | `/admin/index.php?s=paiement` | Vendeur de test avec une boutique de démonstration. | Ouvrir Réglages → Paiement & livraison. | Bureau 1280 px, onglet Paiement & livraison et section Réglages Stripe. | Réglages de paiement de la boutique avec Stripe et les autres prestataires. |
| `encaisser-02-stripe.png` | a-faire | `/admin/index.php?s=paiement` | Même compte de test, mode test. | Remplir les champs de clés Stripe avec des valeurs de test factices, jamais de vraies clés. | Section Stripe avec mode actif, champs et bouton de test. | Champs de clés Stripe en mode test. |
| `encaisser-03-webhook.png` | a-faire | `/admin/index.php?s=paiement` | Même compte de test. | Afficher l'adresse du webhook et le champ du secret de signature. | Zoom sur l'aide webhook et le champ whsec_… | Adresse du webhook Stripe et champ du secret de signature. |
| `encaisser-04-commandes.png` | a-faire | `/admin/orders.php` | Même compte de test, avec une commande de test payée. | Ouvrir la liste des commandes et une commande payée. | Bureau 1280 px, statut Payée et moyen de paiement visibles. | Commande de test payée dans l'administration. |
| `encaisser-05-sepa.png` | a-faire | `/admin/orders.php` | Même compte de test, avec une commande SEPA en attente. | Afficher une commande SEPA et le bouton « Marquer le virement reçu ». | Détail de la commande SEPA, IBAN masqué. | Commande par virement SEPA avec le bouton Marquer le virement reçu. |

## Avant de valider une capture

- Ouvrir l'URL exacte sur le serveur local, avec le compte de test indiqué.
- Reproduire l'action et vérifier que l'écran montre bien l'élément décrit dans le cadrage.
- Masquer les données personnelles et les secrets.
- Enregistrer au nom exact du fichier, en PNG, dans `aide/assets/captures/`.
- Recharger le tutoriel : l'image remplace l'emplacement « capture à faire ».
