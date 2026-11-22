# Protocole de test : publication d'annonces (Leboncoin, Vinted, Facebook Marketplace)

Pack concerné : `packs/annonces-marche` (extension Chrome 1.5.0). Boutique d'essai : `la-fripe-a-naty`.
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
   Version attendue : 1.5.0.
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

## 6. Essai de série (simulation, puis petit lot réel)

Rythme de la publication réelle (la simulation n'est jamais freinée) :
- plage horaire de travail **8 h – 21 h** (heure locale) : en dehors, la file attend la plage suivante, sans se mettre en pause ;
- **3 publications par heure** au plus (heure glissante) et 10 par jour ;
- délai entre deux pièces : réglage de la popup (5 min par défaut, 2 à 30), rendu variable (de 0,8 à 1,4 fois, avec une pause plus longue de temps en temps) ;
- pauses variables entre deux champs, avec parfois un temps de relecture plus long ;
- **vérification, captcha ou téléphone demandés par la plateforme** : arrêt immédiat de la file, et **pas de reprise automatique pendant 6 h** (la simulation reste possible).

Essai en simulation :
1. Boutique : cocher 3 à 4 fiches complètes (prix, état, taille, couleur, photos) dans « Sélectionner les articles ».
2. Popup : **Simulation seule** cochée, intervalle 2 min, « J'assume le risque » cochée, puis **Lancer Leboncoin** (ou Vinted).
3. Attendu : chaque pièce passe à « prepared — prête (simulation) », l'une après l'autre, sans qu'aucun bouton final soit cliqué ; le message de la popup indique la pièce en cours.
4. Tester **Pause** puis **Reprendre** au milieu de la série, puis **Arrêter**.

Petit lot réel : 2 ou 3 pièces maximum, sur un compte dont on accepte le risque, jamais le même jour qu'un autre essai. Les plateformes interdisent l'automatisation : aucun rythme ne supprime le risque de restriction du compte.
