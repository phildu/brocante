# Suivi des tâches ouvertes — Brocs

> Dernière mise à jour : **2026-11-10**
> Source : backlog constitué le 31/10/2026 · migré du dossier `brocs` archivé (2026-11-09) · **16 tâches** (#5 à #20)

## Vue d'ensemble

| # | Tâche | Thème | Priorité | Statut | Dépend de |
|---|-------|-------|----------|--------|-----------|
| 5 | Pitch vidéo pour les proches | Communication | à définir | **en cours** (script livré 01/11, tournage à faire) | — |
| 6 | Trouver les maisons à vider | Acquisition | à définir | à faire | — |
| 7 | Donnerie sur les vide-greniers | Opérations | à définir | **fait** (extension Invendus & dons en prod 07/11) | — |
| 8 | Module bénévoles | Opérations | à définir | **fait** (recrutement + postes buvette, en prod) | — |
| 9 | Module buvette / restauration | Opérations | à définir | **fait** (déployé en prod 07/11) | — |
| 10 | Créer un événement particulier | Événements | à définir | à faire | — |
| 11 | Événement fédéré et itinérant | Événements | à définir | à faire | #10 |
| 12 | Disclaimer objets d'occasion | Juridique | **P1** (rapide) | à faire | — |
| 13 | Types « Marché de Noël » et « Marché des producteurs et créateurs » | Catalogue | **P1** | **fait** (déployé et recetté 04/11) | — |
| 14 | Plan de l'événement en PDF | Opérations | à définir | **en cours** (export PDF/PSD en prod 04/11 ; affiche et encarts à faire) | #10 ou plan existant |
| 15 | Centre d'aide (FAQ, tutos, chatbot) | Support | à définir | **en cours** (`/aide/` en prod 09/11 ; captures et vidéos à tourner) | — |
| 16 | Commerces d'une rue (Google Places) | Prospection | à définir | à faire | clé + quota API |
| 17 | Façade par numéro de rue | Prospection | à définir | à faire | #16 (adresse) |
| 18 | Stripe Connect | Encaissement | **P1** (Notion, 21/11/2026) | **en cours** (code en prod 07/11 ; app Stripe et comptes à brancher) | app Stripe |
| 19 | Alternative à Stripe | Encaissement | **P2** (Notion, 15/12/2026) | **en cours** (comparatif livré, virement SEPA + Revolut en cours, test bloqué) | #18 |
| 20 | Montée en charge OVH | Infrastructure | **P1** (Notion, 30/11/2026) | à faire | mesures de charge |

---

## Détail par thème

### Communication et acquisition

**#5 — Pitch vidéo pour les proches**
Script de 60 à 90 secondes, tourné au téléphone, sans jargon : le problème constaté chez un brocanteur, Brocs en une phrase, la preuve (une boutique en ligne), puis la demande (mise en relation, prêt ou premier client).
- Livrable : script prêt à lire + une prise de vue.
- Statut : fait le 2026-11-01 — script de 200 mots (≈ 83 s) livré dans la session « Script vidéo Brocs » ; reste le tournage.

**#6 — Trouver les maisons à vider (succession, EHPAD)**
Sources d'opportunité : notaires, agences immobilières, syndics, services d'aide à domicile, EHPAD, entreprises de débarras, mairies (service social). Objectif : arriver avant le débarras, vendre le mobilier en vide-maison plutôt qu'en déchetterie, libérer le bien.
- Prochaine action : lister 10 contacts locaux et un message d'approche type.
- À raccorder à #10 et #11.

### Opérations de vide-grenier

**#7 — Service de donnerie**
Accord demandé au déballage, marquage des objets donnés une heure avant la fermeture, point de dépôt ou tournée en vélo cargo, reliquat remis à une recyclerie avec reçu.
- Prévoir : consentement de l'exposant, suivi des dons, bilan par édition.
- **Fait** : extension de galerie « Invendus & dons » en production depuis le 07/11/2026, dans la base de la galerie (`includes/invendus.php`, `includes/invendus-tabs/*`, `portail/galerie-invendus.php`, `INVENDUS_MODULE.md`).
- Circuit couvert : l'exposant propose un objet et reçoit un **lien de suivi privé** (apporté par lui ou à ramasser à son stand) ; en fin de journée il confirme « c'est un invendu » ou retire l'objet s'il est vendu — cela remplace le marquage papier une heure avant la fermeture.
- Ramassage : l'organisateur crée des **tournées** (bénévole, vélo cargo, capacité, affectation des ramassages) ; le bénévole coche « Récupéré » ou « Plus dispo » sur un lien secret.
- Point de collecte : ouvre et ferme aux heures prévues, page publique `/dons.php` où les visiteurs se servent gratuitement, dépôts spontanés possibles.
- Reliquat : remise à une **donnerie enregistrée** (recyclerie, Emmaüs, association) avec **bordereau imprimable** à signer ; cycle de vie tracé, export CSV et statistiques par édition.
- Limites connues : aucun e-mail ni SMS automatique (les liens sont transmis par l'organisateur), pas d'optimisation d'itinéraire.

**#8 — Module bénévoles : besoin et recrutement**
Calcul du nombre de bénévoles selon la surface, le nombre d'exposants, la durée et les créneaux ; génération des postes ; formulaire d'inscription public avec rappels ; planning imprimable et contacts par poste.
- **Fait** : module Recrutement (`includes/recrutement.php`, `portail/recrutement/`) avec trois pistes (emploi, primo-aidant, bénévole), besoins par vide-grenier, codes courts, candidatures et export CSV ; postes de bénévoles de la buvette dans `admin/buvette/benevoles.php`. Aucune génération automatique selon la surface : les postes se créent à la main.

**#9 — Module buvette et restauration**
Carte et prix, stock initial, ventes au comptoir, avances de caisse, clôture et recette nette.
- Contraintes à vérifier : hygiène, déclaration éventuelle, vente d'alcool, tenue de caisse.
- **Fait le 2026-11-07** : extension de galerie `admin/buvette/` (carte, recettes, fournitures, quantités à acheter, prévisionnel, courses, check-list) et page bénévoles au code partagé (`/galerie/<galerie>/buvette/`). La check-list rappelle les démarches officielles, l'autorisation de buvette, l'hygiène et les allergènes, sans se substituer à la mairie.
- Limite assumée : ce n'est pas une caisse enregistreuse, les quantités vendues se saisissent après coup ; les avances de caisse ne sont pas gérées séparément.

**#14 — Plan de l'événement en PDF avec espaces publicitaires**
Export A4/A3 prêt à imprimer : titre, dates, allées et emplacements numérotés, entrées, légende, QR code vers la page en ligne. Variante affiche (plan + exposants + partenaires). Encarts publicitaires payants en option.
- **Fait le 2026-11-04** : l'éditeur de plan (`portail/galerie-plan.php`) exporte l'état affiché en **PDF 300 dpi** ou **PSD calé** (InDesign/Photoshop), déployé sur brocs.arrimage.com.
- Reste : la variante affiche A4/A3 (plan + exposants + partenaires, QR code vers la page en ligne) et les encarts publicitaires payants.

### Événements de particuliers

**#10 — Créer un événement vide-dressing / vide-atelier / vide-maison**
Un particulier ouvre un événement à domicile : dates et horaires, adresse (affichée à l'approbation), description, visuels, promotion partageable, boutique éphémère.
- Différence avec un vide-grenier : pas d'emplacements, un seul vendeur, une adresse privée.
- Prochaine action : modèle de données de l'événement.

**#11 — Événement fédéré et itinérant**
Plusieurs particuliers réunis sous une même affiche : page commune, parcours entre les adresses, promo mutualisée, date d'ouverture et de fermeture, lien de partage unique.
- Cas fondateur : « le vide-dressing des copines ».
- Dépend de #10.

**#12 — Disclaimer objets d'occasion (vendeurs particuliers)**
Mention : objets d'occasion issus de la vie privée du vendeur, non achetés pour être revendus ; vendeur non professionnel ; ni garantie légale de conformité ni droit de rétractation de 14 jours ; vente en l'état, état décrit dans la fiche.
- À décliner sur la fiche produit, le panier/paiement et les CGV.
- Prochaine action : rédiger la mention et l'intégrer (petit lot, faible risque).

### Catalogue

**#13 — Types « Marché de Noël » et « Marché des producteurs et créateurs »**
Dates courtes, chalets ou stands numérotés sur plan, vocabulaire exposant / chalet / allée, tarifs par zone et par durée, page publique éphémère, identité saisonnière.
- **Fait le 2026-11-04** : deux types d'origine ajoutés dans `includes/gallery-types.php` (`marche-de-noel`, `marche-producteurs`), avec lieux de vente, offres (test 5 participants ; Noël 29/79 € pour 35/120 exposants ; producteurs 25/69 € pour 30/90), modules organisateur et file d'attente inclus, marges 50 % et 55 %.
- Pages publiques créées : `/marche-de-noel/` et `/marche-producteurs/` ; liens ajoutés au pied de page et aux pages sœurs, sélecteur de création, tunnel d'inscription et accueil mis à jour.
- **Déployé le 2026-11-04** sur brocs.arrimage.com (15 fichiers, md5 15/15 ; dossiers 705, fichiers 604). Vérifié en ligne : les deux pages répondent 200 avec leurs tarifs (29/79 € et 25/69 €), plafonds 5/35/120 et 5/30/90, marges 50 % et 55 % ; les deux types apparaissent dans le sélecteur `/galerie/creer.php`, l'accueil et le pied de page ; `/inscription/?lieu=…` affiche les bons libellés. Les modules locaux non déployés (messagerie, `/aide/`) sont restés hors du lot.
- **Recette en ligne du 2026-11-04** : six galeries de test créées depuis le portail (`recette-galerie-marchande`, `recette-marche-aux-puces`, `recette-vide-grenier`, `recette-quartier-marchand`, `recette-marche-de-noel`, `recette-marche-producteurs`), puis **publiées** le même jour. Pages publiques `/galerie/<slug>/` en 200 avec le vocabulaire du type, toutes listées dans `/galerie/` ; espace organisateur et file d'attente inclus ; la carte interactive reste proposée en option.
- **Remplissage des six galeries de recette le 2026-11-04** : 4 à 7 commerces membres par galerie (les huit boutiques de production réparties), plan d'implantation enregistré (une zone, huit emplacements numérotés et une entrée) et événement publié avec date, lieu, horaires et tarif. Réservations ouvertes sur les quatre marchés : vide-grenier 30 places à 10/15 €, puces 40 places à 12/18 €, Noël 25 places du 5 au 24/12 à 15/20 €, producteurs 20 places à 8/12 €. Les deux galeries permanentes portent une date d'inauguration (14/11) et de journée des commerçants (21/11). Carte accordée en accès offert aux six galeries ; le module organisateur a été passé en option pour les types « Galerie marchande » et « Quartier marchand » (réglage de type conservé, seules ces deux galeries l'utilisent). Pages publiques vérifiées : plan, membres et date affichés, 0 erreur PHP.

### Support

**#15 — Centre d'aide**
FAQ par rôle (vendeur, organisateur, visiteur, commune), recherche, tutoriels écrits avec captures, vidéos de 60 à 90 s sous-titrées, chatbot.
- Session dédiée ouverte le 2026-11-03 : construction de `/aide/` (FAQ, recherche, 5 tutoriels, chatbot documentaire sans clé, base partagée et widget d'aide contextuelle). Captures et vidéos cadrées par fiches ; pas de faux médias.
- Base de contenu prête le 2026-11-03 dans `contenu-aide/` : 35 questions/réponses, 5 tutoriels, 20 termes de glossaire, 26 fiches de capture et 5 storyboards avec sous-titres VTT. Transmise à la session `/aide/` pour import.
- **En production depuis le 2026-11-09** : `/aide/` public (FAQ par rôle, recherche, tutoriels, glossaire, fiches de capture) et assistant documentaire local (répond uniquement avec les fiches, sans service externe ni conservation).
- Reste : tourner les captures et les vidéos (seuls les 5 sous-titres `.vtt` existent dans `aide/assets/videos/`), puis intégrer le widget d'aide dans le portail et l'administration.

### Prospection des commerces

**#16 — Découverte automatique des commerces d'une rue**
Google Places (Text Search + Place Details) à partir d'une rue et d'une commune : nom, catégorie, adresse, numéro, horaires, téléphone, site, note, photo, proposés en pré-remplissage de l'annuaire.
- Rien n'est publié sans validation. Prévoir quota et facturation.
- Prochaine action : clé API + endpoint de recherche.

**#17 — Image de façade par numéro de rue**
Street View Static API (clé, facturation à l'image, attribution obligatoire, mention « image non contractuelle ») ou Mapillary (couverture inégale).
- Jamais de scraping. Dépend de #16 pour l'adresse.

### Encaissement

**#18 — Connexion automatique du compte Stripe des galeries**
Stripe Connect depuis le portail : bouton « Connecter Stripe », retour, statut du compte, reversements, sans copier-coller de clé.
- Notion : `40a8bad3-34de-8115-96e9-f9d40d89770d` — Technique / P1 — 21/11/2026.
- **Code en production depuis le 2026-11-07** : `includes/connect.php` (paiement « destination charge » sur le compte de la plateforme, commission en `application_fee_amount`), `includes/commissions.php` et `portail/commissions.php` (taux plateforme et galerie, comptes Connect, relevés, versements, export CSV), `data/saas/connect-rates.json` écrit par le portail.
- Reste : activer l'application Stripe Connect, rattacher les comptes réels des galeries, recetter un paiement avec commission puis un premier versement.

**#19 — Alternative à Stripe**
Comparatif écrit (frais, KYC, éclatement vers les galeries, CB, prélèvement, versements) et connecteur de secours testé ; Stripe reste le défaut.
- Notion : `40a8bad3-34de-81dd-a394-c946b4af7062` — Technique / P2 — 15/12/2026.
- Statut : comparatif livré le 2026-11-02 — Mollie Connect recommandé en secours (sous réserve de devis écrit et d'activation des split payments), Mangopay en repli ; Stripe reste le défaut.
- En cours : paiements de secours **virement SEPA** et **Revolut Merchant** (code livré, recette réelle à faire). Test d'un second prestataire toujours bloqué : compte, dossier KYC/KYB, contrat et clés.

### Infrastructure

**#20 — Plan de montée en charge de l'hébergement (OVH)**
Paliers mutualisé → VPS → Public Cloud, seuils de bascule mesurés, coût mensuel par palier, préproduction avant la 10e boutique.
- Notion : `40a8bad3-34de-8115-b214-fa89e14681d8` — Ops / P1 — 30/11/2026.

---

## Retours testeurs importés — 2026-11-03

Les 8 retours ouverts du module Feedback (boutique « La friperie de naty ») ont été transformés en tâches dans `/portail/todo/` en production et en jalons « À faire » de la base Notion « Suivi des jalons ». Le 9e retour, vide (« dsddds dssd »), a été écarté. Chaque tâche porte la référence, le texte d'origine et un lien vers la fiche de retour.

| Réf. | Tâche | Catégorie | Priorité |
|------|-------|-----------|----------|
| fb261103-51f77f55 | IA : statistiques d'usage de Lia et notation des résultats | IA | P2 |
| fb261103-5719b5cc | Annonces : arrêter « place de marché », retester Leboncoin, créer Vinted puis Facebook Marketplace | Boutiques | P2 |
| fb261103-48c596cd | Tableau de bord : frais de la boutique et chiffre d'affaires par mois et par an | Boutiques | P2 |
| fb261103-786a0ae7 | Pack communication : retirer la médiathèque (installée d'office) | Boutiques | P2 |
| fb261103-91550dfb | Créer un pack antiquaire et brocante (QR code, Story, certificat, registre de police) | Boutiques | P3 (gel catalogue) |
| fb261103-d1797b8b | Catalogue : tableau de bord non payant, module payant obligatoire par site | Boutiques | P2 |
| fb261103-fd6921f0 | Intégrer les ventes flash au pack actions commerciales | Boutiques | P2 |
| fb261103-001eb498 | Étiquettes : intégrer la gestion au site et corriger le recalcul des marges | Boutiques | P2 |

**fb261103-51f77f55 — livré en local et déployé en production le 2026-11-03 (9 fichiers, md5 9/9).**
- Journal IA : colonne `provider` ajoutée à `ai_usage` (reprise automatique des appels existants d'après le modèle) ; les générations, textes, recherches, détourages et vidéos consignent désormais leur fournisseur (Google Gemini, Anthropic Claude, Google Veo, SiliconFlow, Modal, fal.ai, fournisseurs OpenAI-compatibles).
- Tableau de bord « Consommation IA » (boutique) : carte « Requêtes IA » (total, ce mois, aujourd'hui), tableau par fournisseur, et bloc « Taux d'efficacité — notations des testeurs » (taux, moyenne, couverture, répartition 5→1, efficacité par fournisseur, 15 dernières notes). « Derniers appels » gagne la colonne fournisseur et un bouton « Noter » par ligne.
- Notation : table `ai_ratings` (une note de 1 à 5 par testeur et par appel, la dernière remplace la précédente), widget flottant « Résultat IA à noter » en bas à gauche après une génération, et formulaire de notation depuis le tableau de bord.
- Portail `/portail/ia.php` : section « 5. Usage et efficacité de l'IA » — totaux serveur, répartition et coûts par fournisseur, usage et efficacité par boutique, dernières notes.
- Formule affichée : taux d'efficacité = notes « Bon » ou « Excellent » (4–5) ÷ résultats notés ; exemple 9/12 = 75 %.
- Tests : 11 tests PHPUnit ajoutés (`tests/AiRatingTest.php`), suite complète 196 tests / 782 assertions OK ; recette locale du widget et de la notation faite (note enregistrée puis effacée de la base de test).
- Déploiement (paquet `/tmp/aiusage-20261103/`, `deploy.sh upload|verify`, `rollback.sh --yes`) : `includes/ai-rating.php` et `admin/ai-rating.php` ajoutés (absents de la production avant envoi), `config.php`, `includes/functions.php` (reconstruit depuis la copie live + une seule ligne `require_once`, pour ne pas embarquer les modules locaux), `includes/admin-nav.php`, `includes/ai-usage.php`, `includes/ai-providers.php`, `admin/ai-usage.php`, `portail/ia.php` écrasés après `diff` ; md5 9/9, permissions 604.
- Contrôles en ligne : `/health.php` 200, `/la-friperie-de-naty/` 200 sans avertissement PHP, `/petit-chalet/` 200 ; `/portail/ia.php` 200 avec la section 5 (197 requêtes, 4,63 € cumulés, Google Gemini 97,2 % / Modal 2,8 %) ; `/la-friperie-de-naty/admin/ai-usage.php`, `admin/ai-rating.php` et `/petit-chalet/admin/ai-usage.php` en 302 vers la connexion admin.

## Retours testeurs — nouvelle vague du 03 au 09/11

Sept nouveaux retours de la boutique « La friperie de naty » ont été relus le 2026-11-10 depuis le module Feedback de préproduction (`data/saas/feedback.json`, 16 entrées, toutes en statut « nouveau »). Trois avaient déjà été publiés automatiquement dans « Suivi des jalons » par le module Retours → Notion ; les quatre plus anciens, antérieurs à l'intégration, ont été ajoutés à la main le 2026-11-10.

| Réf. | Demande | Page | Notion |
|------|---------|------|--------|
| fb261103-134e8fa4 | Appuyer sur le numéro de photo en bas de la fenêtre shooting pour ouvrir l'appareil photo | `admin/shop-shooting.php` | ajouté 10/11 |
| fb261103-90f95147 | Proposer l'assistance vocale dès le premier écran de l'assistant Photos | `admin/shooting-assistant.php` | ajouté 10/11 |
| fb261103-90ccc8c1 | Mettre la photo recto d'analyse du premier écran dans le champ recto du parcours photo | `admin/shooting-assistant.php` | ajouté 10/11 |
| fb261104-3b428a1e | Ouvrir l'appareil photo avec l'interface iPhone pour régler la focale | `admin/shooting-assistant.php` | ajouté 10/11 |
| fb261105-96d540b2 | Après le footing audio, ne plus voir ni entendre le message | `admin/shooting-assistant.php` | publié 05/11 |
| fb261105-dd45daa0 | À l'ouverture du Fitting photo, se mettre sur l'article par défaut | `admin/shooting-assistant.php` | publié 05/11 |
| fb261109-b4af79a9 | Compte à plusieurs rôles : proposer le choix du rôle à la connexion | `admin/accounts.php` | publié 09/11 |

Les sept sont « À faire », Technique / P1 dans Notion. Quatre portent sur le parcours photo (shooting boutique et assistant), deux sur l'audio (assistance vocale, message du footing) et un sur la connexion multi-rôles.

## Contraintes en vigueur

- **Gel du catalogue** : aucun nouveau pack avant trois pilotes payants (#13 concerné).
- **Déploiement** : additif d'abord, jamais d'écrasement d'un fichier live avant test, comparaison MD5, copie de retour arrière, permissions **604 fichiers / 705 dossiers**, accord explicite avant toute action en production.
- **Secrets** : jamais affichés ni versionnés (sortie lftp filtrée).
- **Cartographie** : pas de tuiles OpenStreetMap (compte banni) ; IGN Géoplateforme, repli CARTO/Esri.
- **Notion** : hub « Brocs — Pilotage du projet » et base « Suivi des jalons » à synchroniser après chaque livraison.

## Hors backlog

| # | Tâche | État |
|---|-------|------|
| 21 | Surveillance + moniteur externe | fait le 31/10/2026 |
| 22 | UptimeRobot sur 5 URL | obsolète — couvert par la sonde GitHub Actions `phildu/brocs-surveillance` (5 min) |
| 23 | Module « Tâches et paramétrages » du portail | déployé le 02/11/2026 sur brocs.arrimage.com — `/portail/todo/` + carte sur le dashboard ; 13 réglages pré-remplis ; recette locale et production OK |
| 24 | Module Rendez-vous (Doodle / Framadate) | déployé le 03/11/2026 sur brocs.arrimage.com — pack `packs/rendez-vous` + page publique `/rendez-vous.php` ; activé sur les 8 boutiques, pages publiques 200 ; recette d'un rendez-vous réel encore à faire |
| 1–4 | Générateur `/dossier/` (bibliothèque, formulaire, aperçu/ZIP, tests) | fait le 31/10/2026 |

## Journal

| Date | Événement |
|------|-----------|
| 2026-11-01 | Backlog réimporté dans la session ; #10 remise en attente ; #22 marquée obsolète ; création de ce fichier. |
| 2026-11-01 | #5 pitch vidéo lancée dans une session dédiée. |
| 2026-11-01 | #5 livrée : script de 200 mots (≈ 83 s), plans de tournage et variantes d'appel à l'aide ; tournage à faire. |
| 2026-11-01 | #19 alternative à Stripe lancée dans une session dédiée. |
| 2026-11-02 | #19 : comparatif livré (Mollie Connect en secours, Mangopay en repli) ; test réel bloqué faute de compte, KYC et clés. |
| 2026-11-02 | Module « Tâches et paramétrages » ajouté au portail : base SQLite `data/todo/todo.db`, page `/portail/todo/` (ajout, édition, filtres par état et catégorie, fait / à faire, suppression), carte « Paramétrages à faire » en tête du dashboard avec cases à cocher. 13 réglages pré-remplis (webhooks Stripe, test paiement, Stripe Connect, Gemini, SMS, suivi e-mails, alertes, notifications, assistance, recrutement, OVH, sauvegarde, domaine). Les trois tâches de paiement portent maintenant le mode opératoire exact (URL du webhook par boutique, carte de test, activation Connect). Recette locale OK ; non déployé. |
| 2026-11-02 | Module « Tâches » déployé sur brocs.arrimage.com : 4 fichiers dans `portail/todo/` (addition pure, 705/604, MD5 4/4) et `portail/index.php` remplacé après sauvegarde, MD5 identique. La carte affiche 13 tâches, la page répond en 200 derrière la connexion, un aller-retour fait/à faire a été testé puis annulé (13 à faire, 0 faite), `data/todo/todo.db` renvoie 403. Non déployé volontairement depuis l'arbre local : l'indicateur « Équipe du projet » et les statuts/aperçus `shop_state_*` du dashboard (travail d'une autre session), et le lien « Forces de vente » a été conservé. Retour arrière : `/tmp/td-deploy-20261102/backup/portail-index.php` à remettre en place, puis supprimer `portail/todo/`. |
| 2026-11-01 | Tunnel refondu (lot en attente de déploiement, 11 fichiers) : étape 1 « Je veux vendre en ligne » commence par le lieu de vente (chez moi / galerie marchande / puces / vide-grenier / quartier marchand), affiche les galeries du type choisi (vignette, description, visite, sélection) et garde la galerie choisie ; étape 2 prend le vocabulaire « boutique » ou « stand » ; plugin « installé d'office » (médiathèque, tableau de bord) à la création ; catalogue de plugins pilotable en BO (état test/gratuit, activités, vente en ligne, propositions) ; adresse autocomplétée (BAN + géolocalisation) et profil particulier/professionnel à l'étape 2. Recette locale OK (PHPUnit 92 tests). Déploiement à confirmer. |
| 2026-11-01 | Lot tunnel déployé sur brocs.arrimage.com : 11 fichiers, sauvegarde préalable, MD5 11/11, permissions 604, pages publiques et portail en 200 sans erreur PHP. Retour arrière : `/tmp/brocs-pack-2026-11-01-funnel/rollback.sh --yes`. Non livrés : les aperçus de landing au survol de l'accueil et des fiches galerie, car `gt_landing_preview_html()` n'existe pas encore en production ; ces deux fichiers n'ont reçu que les CTA. |
| 2026-11-01 | Lot « aperçus de landing » déployé : `includes/gallery-types.php` (composant partagé `.lp-hover` / `.lp-peek` / `.lp-pop`, accès clic, clavier et survol) + `accueil/index.php`, `galerie/index.php`, `galerie/creer.php` (remplacement des aperçus inline). `includes/landing.php` était déjà identique en production. MD5 4/4, permissions 604, aucune erreur PHP, vérifié au navigateur à 390 px et 1280 px. Retour arrière : `/tmp/brocs-pack-2026-11-01-landing-preview/rollback.sh --yes`. |
| 2026-11-02 | Recherche globale du portail (⌘K) déployée : la palette interroge désormais le contenu des pages et les fiches (boutiques, galeries, équipe, plugins, inscriptions, tâches), pas seulement les titres. `portail/_search.php` (index caché dans `data/portail/search-index.json`, 43 pages + 12 pages `vente`), endpoint JSON `portail/recherche.php`, `_chrome.php` (placeholder + `admin.js?v=2`) et `admin.js`. MD5 4/4, permissions 604, dérive contrôlée avant écrasement ; `stripe` renvoie 14 résultats en ligne, `prospect` les pages Forces de vente, accès anonyme refusé. Retour arrière : `/tmp/brocs-search-20261102/rollback.sh --yes`. |
| 2026-11-02 | Recette clavier en production validée : Meta+K ouvre la palette et place le focus, `stripe` renvoie 17 résultats (14 + 3 tâches du module todo), flèche bas puis haut déplacent la sélection (butée stable), Entrée ouvre `/portail/todo/`, Échap ferme, `prospect` renvoie 12 résultats et Entrée ouvre `/portail/vente/prospects.php`. Aucune erreur console. |
| 2026-11-03 | #15 centre d'aide lancé dans une session dédiée : `/aide/` public, FAQ par rôle, recherche, 5 tutoriels, chatbot documentaire, base partagée et widget d'aide contextuelle. Aucun déploiement demandé. |
| 2026-11-03 | #15 base de contenu livrée dans `contenu-aide/` : 35 questions/réponses, 5 tutoriels pas à pas, 20 termes de glossaire, 26 fiches de capture, 5 storyboards et sous-titres VTT. JSON validés, URL statiques contrôlées ; transmise à la session `/aide/`. Captures et vidéos restent à tourner. |
| 2026-11-03 | #15 intégration démarrée par la session `/aide/` : `brocante/aide/contenu/faq.php` créé (FAQ rédigée à partir du code, slugs propres). Le pack `contenu-aide/` reste intact et validé (35/5/26/5/20, aucune référence cassée) ; réconciliation des slugs et import des tutoriels, captures et vidéos à contrôler à la fin de la session. |
| 2026-11-02 | Module Équipe déployé sur brocs.arrimage.com : 5 ajouts (`includes/equipe.php`, `portail/equipe.php`, `equipe-action.php`, `equipe-import.php`, `equipe-inscription.php`) + 2 remplacements reconstruits depuis la copie live (`portail/_chrome.php` : entrée de menu, icônes, pastille ; `portail/index.php` : indicateur « Équipe du projet »). MD5 7/7, permissions 604, aucune dérive avant écrasement. Recette locale : création / changement de statut / suppression d'un membre, candidature publique en 5 étapes puis acceptation, suppression et nettoyage, CSRF refusé (400). En ligne : pages protégées renvoyant la connexion, parcours public 200, 7 pages connectées 200 sans avertissement PHP, `data/equipe/*` en 403. Suite PHPUnit non exécutable localement (`includes/payments/revolut.php` absent de l'arbre, sans rapport avec Équipe). Retour arrière : `/tmp/equipe-20261102/backup/`. Non déployé : travail local d'autres sessions (aperçus `shop_state`, statut de boutique, entrée « Boîte locale ») ; `_search.php` et `includes/recrutement.php` étaient déjà identiques en production. |
| 2026-11-03 | Recherche ⌘K étendue à l'administration des boutiques et des galeries, déployée sur brocs.arrimage.com : 4 ajouts (`admin/_search.php` moteur boutique — index des 35 pages admin dans `data/search/admin-pages.json`, 13 sources SQL protégées par try/catch, filtres par rôle ; `admin/recherche.php` endpoint JSON 401 sans session ; `assets/admin-palette.css` ; `portail/_search-galerie.php` moteur galerie — membres, admins sans hash, prospects, inscriptions, réservations, file d'attente, bourse, zones du plan, sondages) + 5 remplacements (`includes/admin-nav.php` : loupe sidebar/topbar, dialogue et palette ; `includes/accounts.php` : `recherche.php` ouverte au community manager ; `portail/recherche.php` : branche `GA_LIMITED` ; `portail/_bootstrap.php` : `recherche.php` dans `GA_PAGES` ; `portail/_chrome.php` : palette débloquée en mode restreint). Trois fichiers reconstruits depuis la copie live pour ne pas expédier le code local-only (rôle `photographe`, entrée « Boîte locale », URLs `php -S` en IP). MD5 9/9, permissions 604, `/admin/recherche.php` et sa variante préfixée en 401, CSS 200/3748 o, moteurs inaccessibles, pages portail derrière la connexion. Recette locale : 77 fiches indexées sur une galerie vide-grenier, 0 fuite de secret, requêtes 1,5-3 ms. Retour arrière : `/tmp/recherche-complete-20261103/rollback.sh --yes`. Reste : recette clavier en ligne (⌘K, flèches, Entrée) dans une session admin ouverte par l'utilisateur. |
| 2026-11-03 | Module Rendez-vous (Doodle / Framadate) construit dans `brocante` : pack `packs/rendez-vous` (manifeste `rdv_`, 1 migration, bibliothèque, API admin, 2 écrans, page publique et entrée de pied de page) + shim racine `rendez-vous.php`. Fonctions : dates/heures souples (`2026-11-14`, `14/11/2026 9h-12h`), générateur de créneaux, lien public sans compte, oui / peut-être / non, modification par lien e-mail, tableau des réponses, meilleur créneau, confirmation + e-mail aux participants, relance, clôture, annulation, suppression. Recette locale sur une boutique jetable (création, réponse publique, doublon e-mail refusé, modification, confirmation, relance, clôture, réouverture, annulation, suppression), e-mails dans `data/mail-outbox.log`, mobile 390 px, aucune erreur PHP. PHPUnit 13 nouveaux tests, suite complète 155/155. Lot additif `/tmp/rdv-deploy-20261103/` (`deploy.sh add|verify`, `rollback.sh --yes`, 11 fichiers, MD5 11/11 en local, permissions 604/705) prêt mais **non déployé** ; activation boutique par boutique ensuite dans `/portail/plugins.php`. |
| 2026-11-03 | Retours testeurs importés dans la liste de tâches : 8 retours ouverts du module Feedback (boutique « La friperie de naty ») transformés en tâches dans `/portail/todo/` sur brocs.arrimage.com (8/8 vérifiées) et en jalons « À faire » de la base Notion « Suivi des jalons ». Le retour vide « dsddds dssd » a été écarté. Chaque tâche porte la référence `fb…`, le texte d'origine et un lien vers la fiche de retour ; la page 02 — Jalons techniques consigne l'import. Aucun fichier ni base n'a été remplacé. |
| 2026-11-03 | Import automatique feedback → Notion **déployé** sur brocs.arrimage.com (accord utilisateur). `includes/notion-todo.php` (autonome) publie chaque retour de 15 caractères minimum dans la base « Suivi des jalons » : `Jalon` (type + extrait + réf.), `Critère de done` (texte, boutique, page complète, réf.), `Domaine` déduit (Pricing/Commercial/Ops/Testing, Technique par défaut), `Priorité` (P0 bloquant, P1 gênant, P2 sinon), `Statut` À faire. Dédoublonnage en recherchant la réf. `fb…` dans « Critère de done ». Jeton dans `.secrets/notion.json` (0600) ; échec mis en file dans `data/saas/notion-pending.json` et réessayé, jamais bloquant pour l'envoi du retour (réponse HTTP envoyée avant les imports via `fastcgi_finish_request`). Réglages : nouvel onglet `/portail/api.php?s=notion` (« Retours → Notion ») avec test de connexion, file d'attente et mode d'emploi de l'intégration. **URL capturée** : le widget envoie `location.href` (fragment retiré), le retour stocke un champ `url` validé http(s), affiché en lien dans `/portail/retours.php`, dans l'e-mail d'alerte et dans les tâches. PHPUnit 184/184 (dont 12 tests Notion). Lot `/tmp/notion-todo-20261103/` : 7 fichiers. En ligne : dérive contrôlée avant écrasement (aucune), MD5 7/7 après re-téléchargement, permissions 604, PHP 8.5, lint propre, `/portail/api.php?s=notion` 200, `feedback-api.php` 401 sans session (les trois includes se chargent), widget `?v=3` 200 / 21 818 o. Retour arrière : `/tmp/notion-todo-20261103/deploy.sh rollback --yes`. Reste : créer l'intégration Notion, la partager avec la base « Suivi des jalons » et coller le jeton `ntn_…` — sans jeton la fonction reste muette. |
| 2026-11-03 | Import automatique feedback → tâches **déployé** sur brocs.arrimage.com. `includes/feedback-todo.php` (nouveau, autonome, ne charge pas le portail) : dédoublonnage par `tasks.source = feedback:<réf>` (index unique partiel), filtre des messages de moins de 15 caractères, catégorie par mots-clés, priorité selon la gravité (bloquant → prioritaire), lien vers `/portail/retours.php?focus=…`, migration idempotente de la colonne `source` sur la base existante. `admin/feedback-api.php` reçoit deux lignes après `feedback_add()` ; l'import attrape toutes les exceptions, il ne peut donc jamais faire échouer l'envoi du retour. Vérifications : PHPUnit 142/142 (dont 8 nouveaux tests), migration et dédoublonnage sur une copie de la base réelle (les 8 imports manuels sont reconnus, aucune ligne ajoutée), essai de bout en bout `feedback_add()` → tâche créée puis réimport sans doublon. En ligne : md5 2/2, permissions 604, `feedback-api.php` 401 sans session, `/portail/retours.php` et `/portail/todo/` derrière la connexion, l'include en 404 direct. La création d'une tâche en production se vérifiera au prochain retour réel. Retour arrière : `/tmp/feedback-todo-20261103/rollback.sh --yes`. Notion reste manuel. |
| 2026-11-03 | Module Rendez-vous **déployé** sur brocs.arrimage.com (accord utilisateur) : 11 fichiers en ajout pur (`packs/rendez-vous/` 10 fichiers + `rendez-vous.php`), MD5 11/11 après re-téléchargement, permissions 604 fichiers / 705 dossiers. Avant activation, `/rendez-vous.php` et `/la-friperie-de-naty/rendez-vous.php` répondaient 404. Plugin activé depuis le portail sur `la-friperie-de-naty` (migration des 5 tables `rdv_*` exécutée dans sa base) : statut « Actif · v1.0.0 », page publique 200, CSS `packs/rendez-vous/assets/rdv.css` 200 / 9 092 o, lien « Prendre rendez-vous » présent dans le pied de page de la boutique. Les 7 autres boutiques restent « Non activé ». Retour arrière : désactiver le plugin dans `/portail/plugins.php`, puis supprimer `packs/rendez-vous/` et `rendez-vous.php` ; les tables `rdv_*` restent inertes. Reste : créer un premier rendez-vous et enregistrer une réponse publique en production (connexion admin à faire par l'utilisateur). |
| 2026-11-03 | Module Rendez-vous activé sur les 7 autres boutiques (accord utilisateur) : `annee-70`, `essai`, `mes-fringues`, `mes-livres`, `mes-robes`, `petit-chalet`, `philofil`. Statut « Actif · v1.0.0 » sur les 8 boutiques après rechargement du portail, migration `rdv_*` exécutée dans chaque base, `/rendez-vous.php` à 200 partout. La page publique ne montre encore aucun rendez-vous. Reste la recette d'un rendez-vous réel avec réponse publique. |
| 2026-11-04 | Six galeries de recette remplies sur brocs.arrimage.com : commerces membres, plan (zone + 8 emplacements + entrée) et événement daté. Quatre événements avec réservation ouverte, deux journées pour les galeries permanentes. Carte offerte aux six galeries, organisateur passé en option pour les types marchande et quartier. Pages publiques en 200, plan et date affichés, aucune erreur PHP. |
| 2026-11-04 | Laboratoire des appels à l'action construit dans `brocante` (local seulement, non déployé) : chaque CTA du site public devient réglable et testable. `includes/cta.php` (registre de 20 emplacements groupés, variantes pondérées, jetons HMAC, compteurs SQLite `data/saas/cta.db`), `cta-track.php` et `assets/cta-track.js` (affichage compté à 50 % visible pendant 1 s, clic, une fois par session ; robots exclus ; frein 240 événements/min par IP), page `/portail/ctas.php` (libellés, jusqu'à 6 variantes et leur poids, tableau affichages / clics / taux, meilleure variante dès 50 affichages, export CSV, retour au libellé d'origine) avec entrée « Appels à l'action » dans le menu Boutiques. Branchements : en-tête du site, accueil (bandeau, cartes des types, bandeaux de fin), six pages d'atterrissage (héros, formules, bandeau de fin), annuaire, liste et fiches de galerie (bandeau, bouton flottant) — les libellés personnalisés d'une galerie restent prioritaires. PHPUnit 258/258 (13 nouveaux tests). Recette locale de bout en bout : variante enregistrée puis servie sur `/annuaire/`, affichage et clic comptés, jeton invalide refusé, remise à zéro. Déploiement à confirmer. |
| 2026-11-04 | Laboratoire des appels à l'action **déployé et recetté** sur brocs.arrimage.com (accord utilisateur « fait les 3 propositions »). Paquet `/tmp/cta-deploy-20261104/` (`deploy.sh add|overwrite|verify` + `rollback.sh --yes`) : 4 ajouts (`includes/cta.php`, `cta-track.php`, `assets/cta-track.js`, `portail/ctas.php`) puis 6 remplacements (`includes/saas.php`, `includes/landing.php`, `accueil/index.php`, `annuaire/index.php`, `galerie/index.php`, `portail/_chrome.php`). `saas.php` et `_chrome.php` reconstruits depuis la copie live : le module local `aide`, `domain_ip` et l'entrée « Boîte locale » sont restés hors du lot. MD5 10/10 après re-téléchargement, permissions 604, aucune dérive avant envoi. En ligne : neuf pages publiques et portail en 200 sans warning PHP avec le marquage `data-cta`, page CTA derrière la connexion (200 authentifiée), `/cta-track.php` GET 405, `assets/cta-track.js` 200, `includes/cta.php` 404, `data/saas/cta.db` 403. Recette en ligne sur `annuaire_cta` : variante temporaire « Créez votre boutique en ligne » (`v1`, poids 100) servie, 1 affichage et 1 clic enregistrés (taux 100 %), jeton invalide refusé, export CSV correct, retour au libellé d'origine avec compteurs conservés en « Ancienne variante (v1) ». PHPUnit 258/258. Notion : doc 02 et doc 03 (§47) à jour, jalon Technique et jalon Testing passés à Fait, ligne Ops ajoutée, hub à 201 tâches. Retour arrière : `/tmp/cta-deploy-20261104/rollback.sh --yes`. Reste : observation avec de vrais visiteurs sur un emplacement fréquenté. |
| 2026-11-04 | Première variante **réelle** enregistrée sur le laboratoire des CTA en production (`annuaire_cta`, bandeau de fin de `/annuaire/`) : `v2` « Je veux vendre en ligne » (témoin, poids 50) et `v3` « Rejoindre l'annuaire » (alternative, poids 50). Le témoin est enregistré comme variante car dès qu'une variante active existe le libellé d'origine n'est plus servi automatiquement. Vérifié : 30 chargements de `/annuaire/` donnent 14 × `v2` et 16 × `v3`, compteurs à 0 au départ, historique de recette conservé en « Ancienne variante (v1) » (1 affichage, 1 clic). Lecture dans `/portail/ctas.php?jours=7` (meilleur taux dès 50 affichages par variante) ou export CSV. |
| 2026-11-06 | Buvette et événement de galerie généralisés : `60221ea` ajoute les démarches officielles du vide-grenier et la charte du bénévole occasionnel ; `bfc04ea` ajoute l'annulation d'événement, l'alerte aux exposants et les remboursements. |
| 2026-11-07 | Fichier `brocs` archivé (`brocs-archive-2026-11-09.tar.gz`) après suppression des secrets et du profil navigateur ; ce backlog est le seul élément à conserver et il a été recopié dans `docs/SUIVI_TACHES.md` le 2026-11-10. |
| 2026-11-07 | Déploiement en production de la buvette, des invendus & dons, de l'annulation d'événement et de Stripe Connect (tags `prod-2026-11-07-*`). |
| 2026-11-09 | Déploiement en production de 4aba40e (`prod-2026-11-09-2316`) : journal des dépenses, MCP OAuth, horaires de tournée, lot de l'après-midi (vente express, sponsors, top bar, retours testeurs, identité, centre d'aide, impact numérique). |
| 2026-11-10 | Backlog relu contre le code et la production : #7, #8 et #9 passent « fait » (donnerie livrée par l'extension Invendus & dons), #14 et #15 « en cours » (export PDF en ligne, affiche et médias à faire), #18 et #19 « en cours ». Les 11 tâches absentes de Notion y ont été créées ; #18 et #19 mis à jour. |
| 2026-11-10 | Sept retours testeurs de la boutique La friperie de naty (03 au 09/11) relus depuis la préproduction ; trois étaient déjà publiés dans Notion, les quatre plus anciens y ont été ajoutés. |
| 2026-11-10 | Publication réseaux sociaux détachée en pack indépendant `packs/publication-rs/` : deux pages (publication, API & Réseaux), API `eb80` réduite (lecture média et envoi pour le community manager, réglages et jetons pour les administrateurs, `/rs/schedule/run` réservé au cron) et cron propre. Le pack partage la base `data/com/<boutique>.db`, les `uploads/<boutique>/` et le verrou `data/com/<boutique>.cron.lock` avec le pack Communication, donc jetons, programmations et images existants resservent et un post dû n'est publié qu'une fois. Testé localement sur `petit-chalet` (pages 200, onglets réduits, appels API, cron à 0 publication, lint propre). Non commité ni déployé. |
