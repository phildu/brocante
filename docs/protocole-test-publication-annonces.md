# Protocole de test : publication d'annonces (Leboncoin, Vinted, Facebook Marketplace)

Pack concerné : `packs/annonces-marche` (extension Chrome 1.4.3). Boutique d'essai : `la-fripe-a-naty`.
Les publications partent du navigateur du commerçant, jamais du serveur. Faire les tests avec un compte dont on accepte
le risque de restriction.

## État de départ de la friperie (local)

- Accès offert posé : `am_settings.subscription_status = complimentary` (sinon l'API répond 402).
- Un seul article au catalogue : « Hoodie noir Stuttgart Panthers ». Son prix est une fourchette (« 25-35 € »), que les
  formulaires n'acceptent pas : mettre un prix chiffré (par exemple 30 €) et 2 ou 3 photos avant le test.
- Jeton `am_…` et sélection d'articles : à créer dans l'admin (étape 0).

## 0. Préparation

1. Admin de la friperie → plugin « Annonces LBC / Vinted » activé.
2. Créer le jeton `am_…` (modale « Clé API et connexion »).
3. Fiche du hoodie : prix chiffré, photos.
4. Modale « Sélectionner les articles pour l'application » : cocher le hoodie.

## 1. Extension

1. `chrome://extensions` → mode développeur → « Charger l'extension non empaquetée » → `packs/annonces-marche/extension`.
   Version attendue : 1.4.3.
2. Réglages : adresse `https://brocante.test/la-fripe-a-naty/boutique.php` + jeton, puis **Tester**.
3. Attendu : connexion confirmée ; le hoodie apparaît dans la recherche.

## 2. Test A : préremplissage manuel (aucun risque)

1. Se connecter à la plateforme dans Chrome, ouvrir la page de dépôt.
2. Fiche du hoodie → **Remplir Leboncoin** (ou **Remplir Vinted**).
3. Vérifier : titre, description, prix ; état, marque, couleur, taille si le champ existe ; panneau latéral ouvert ;
   champs tardifs remplis après le choix de la catégorie ; photos jointes ou message « à ajouter à la main ».
4. Ne pas publier. Quitter la page sans enregistrer.

## 3. Test B : mode automatique en simulation (rien n'est mis en ligne)

1. Réglages du mode automatique : **Simulation seule** cochée, intervalle 2 min.
2. Cocher « J'assume le risque », puis **Lancer Leboncoin** (ou **Lancer Vinted**).
3. Attendu : file « en cours », hoodie rempli, classé **simulé** dans l'historique, bouton « Publier » jamais cliqué.
4. Tester **Pause**, **Reprendre**, **Passer**, **Arrêter**.
5. Fermer puis rouvrir la popup en cours de file : la file reprend où elle en était.
6. Catégorie demandée par la plateforme : la file se met en pause, l'onglet reste ouvert ; choisir la catégorie à la main,
   puis **Reprendre**.

## 4. Test C : vraie publication (un seul article, une seule fois)

1. Décocher Simulation seule ; hoodie seul dans la sélection ; relancer.
2. Attendu : annonce en ligne ; historique « publié » ; ligne dans `am_listings` ; la référence figure dans
   `GET /api/annonces/publications`.
3. Relancer : le hoodie ne doit pas être republié (anti-doublon).
4. Supprimer ensuite l'annonce de test sur la plateforme.

## 5. Cas d'arrêt à provoquer si possible

- Jeton invalide : message d'erreur clair.
- Article sans prix : arrêt sur « champs requis : … ».
- Captcha ou vérification d'identité : arrêt immédiat.
- Quota quotidien atteint : arrêt de la file.

## À relever pendant chaque test

Champs non reconnus, catégorie (auto ou à la main), photos (jointes ou refusées), durée totale, messages de sécurité.

## Spécificités par plateforme

| Plateforme | Support dans le pack | Remarques |
|---|---|---|
| Leboncoin | Oui (`content/leboncoin.js`) | Formulaire par étapes : description et prix apparaissent après la catégorie. |
| Vinted | Oui (`content/vinted.js`) | Nature et sous-catégorie seulement suggérées : choisir la catégorie finale à la main. |
| Facebook Marketplace | **Non** | `manifest.json` ne cible que Leboncoin et Vinted. Il faut d'abord développer un script de remplissage (`content/facebook.js`), l'ajouter au manifeste et aux réglages avant de pouvoir appliquer ce protocole. |

## Journal des essais

| Date | Plateforme | Test (A/B/C) | Résultat | Remarques |
|---|---|---|---|---|
| | | | | |
