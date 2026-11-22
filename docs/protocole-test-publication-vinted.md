# Protocole de test : publication sur Vinted

Pack : `packs/annonces-marche` (extension Chrome 1.6.0). Boutique d'essai : `la-fripe-a-naty`.
Complète `protocole-test-publication-annonces.md` (version commune, préparation et extension).
Les publications partent du navigateur du vendeur, jamais du serveur. Utiliser un compte Vinted dont on accepte le risque
de restriction (Vinted interdit l'automatisation dans ses conditions).

## Prérequis (déjà en place pour la friperie)

- Accès offert posé : `am_settings.subscription_status = complimentary`.
- Jeton `am_…` créé dans l'admin, extension chargée et réglée (voir protocole commun, étapes 0 et 1).
- Hoodie : **prix chiffré** (le « 25-35 € » actuel est refusé), 2 ou 3 photos, coché dans la sélection.
- Spécifique Vinted : un hoodie doit avoir **état**, **taille**, **marque** et **couleur** renseignés dans la fiche, sinon
  Vinted bloque la publication (champs requis). Compte Vinted connecté dans le même profil Chrome, adresse de livraison et
  moyen de paiement déjà configurés.
- Page de dépôt par défaut : `https://www.vinted.fr/items/new` (modifiable dans « Réglages du mode automatique »).

## Ce que l'extension remplit sur Vinted

Titre, description, prix, état (libellés Vinted : « Neuf avec étiquette », « Neuf sans étiquette », « Très bon état », « Bon
état », « Satisfaisant »), marque, taille, couleur, matière, puis la catégorie **en indication seulement**
(nature › sous-catégorie du catalogue). La catégorie finale, la livraison (colis, poids) et le mode de remise se choisissent
**à la main**.

## Test A : préremplissage manuel (aucun risque)

1. Ouvrir `https://www.vinted.fr/items/new`, connecté.
2. Popup → fiche du hoodie → **Remplir Vinted**.
3. Vérifier : titre, description, prix ; état avec le bon libellé Vinted ; marque, taille, couleur ; panneau latéral ouvert ;
   photos jointes (ou message « à ajouter à la main ») ; message « catégorie à choisir : … » si la catégorie n'est pas
   préremplie.
4. Choisir la catégorie à la main : les autres champs ne doivent pas être écrasés.
5. Ne pas publier ; quitter sans enregistrer (ou enregistrer en brouillon Vinted si on veut tester le brouillon).

## Test B : mode automatique en simulation

1. Réglages du mode auto : **Simulation seule** cochée, intervalle 2 min. « J'assume le risque » cochée.
2. **Lancer Vinted**.
3. Attendu : file « en cours », formulaire rempli, pièce classée **simulée**, bouton « Mettre en ligne » jamais cliqué.
4. Vinted impose en général la catégorie et la livraison : la file doit **se mettre en pause avec l'onglet ouvert**.
   Compléter à la main, puis **Reprendre**. Tester aussi **Passer**, **Pause**, **Arrêter**.
5. Fermer puis rouvrir la popup en cours de file : reprise au même point.

## Test C : vraie publication (un seul article, une fois)

1. Décocher Simulation seule ; hoodie seul dans la sélection ; **Lancer Vinted**.
2. Compléter la catégorie et la livraison à la demande de la file, puis **Reprendre**.
3. Attendu : annonce en ligne sur Vinted ; historique « publié » ; ligne dans `am_listings` ; la référence figure dans
   `GET /api/annonces/publications?channel=vinted`.
4. Relancer : le hoodie ne doit pas être republié.
5. Supprimer l'annonce de test sur Vinted.

## Cas d'arrêt à provoquer

- Jeton invalide : message clair.
- Article sans prix, sans taille ou sans état : arrêt sur « champs requis : … ».
- Vérification (captcha, identité, téléphone) : arrêt immédiat.
- Photos refusées : pause, ajout à la main, reprise.
- Quota quotidien atteint.

## À relever

Champs non reconnus, libellé d'état accepté ou non, catégorie (auto ou main), photos, livraison, durée totale, tout message
de sécurité de Vinted. Noter aussi si Vinted a changé l'adresse ou le libellé du bouton de mise en ligne.

## Journal des essais

| Date | Test (A/B/C) | Résultat | Remarques |
|---|---|---|---|
| | | | |
