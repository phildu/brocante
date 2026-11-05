# Module Comptage de foule — vide-grenier

Compter les visiteurs d'un vide-grenier depuis un smartphone, **à la main**
(gros bouton **+1** à l'entrée) **ou automatiquement avec la caméra** du
téléphone, et suivre en direct le nombre de visiteurs, la fréquentation en
temps réel, le pic de la journée et la courbe heure par heure.

- **Responsable** : *Administration → Comptage de foule* (`/admin/comptage/`), même mot de passe que le reste de l'admin.
- **Bénévoles aux entrées** : `/comptage/`, depuis un téléphone, avec un **code partagé**. Aucun compte, aucune donnée personnelle : on ne mémorise que des nombres et des heures.

## Pour démarrer

1. *Comptage de foule → Événements & postes* : créer l'événement (nom, date, lieu, capacité d'accueil si le site est limité). Un poste « Entrée principale » est créé ; ajouter un poste par entrée supplémentaire (parking, côté église…).
2. *Accès bénévoles* : définir un **code**, puis donner aux bénévoles le lien (ou le **QR code** à scanner, à afficher ou à imprimer en affiche) et le code. Le QR code ne contient que le lien, jamais le code.
3. Chaque bénévole ouvre le lien **une fois avec du réseau** et choisit « Ajouter à l'écran d'accueil » : le compteur s'ouvre ensuite comme une application, même sans réseau.
4. Faire un essai la veille, puis *Résultats → Corriger un comptage ou tout remettre à zéro* pour repartir de 0.

## Le compteur (téléphone)

| Élément | Rôle |
|---|---|
| **+1 entrée** (gros bouton) | un visiteur qui entre |
| **×2 ×3 ×5 ×10** | à choisir *avant* d'appuyer pour une famille ou un groupe (repasse à ×1 après chaque appui) |
| **−1 sortie** | un visiteur qui sort (si l'option « compter aussi les sorties » est active) |
| **↩ Annuler** | retire le dernier appui, autant de fois que nécessaire |
| Postes (en haut) | si l'événement a plusieurs entrées, on choisit la sienne |
| Gros chiffre | personnes sur place (ou visiteurs comptés si les sorties ne sont pas suivies), avec une **jauge de capacité** verte / orange / rouge |
| Pastille en haut à droite | `✓ À jour`, `↻ n en attente` ou `⚠ Hors ligne` |

Vibration, son et « écran allumé » se règlent en bas de l'écran.

### Hors connexion

Les appuis sont enregistrés **sur le téléphone** avec leur heure exacte, puis
envoyés dès que le réseau revient (et toutes les 10 secondes sinon). Chaque
appui a un identifiant unique : un envoi répété après une coupure ne compte
jamais deux fois. Le compteur affiche toujours « derniers totaux connus + appuis
en attente », donc il réagit instantanément, réseau ou pas. Si la session
expire, un bandeau propose de se reconnecter — les appuis restent sur le
téléphone. À éviter : vider les données du navigateur tant que la pastille
indique des appuis en attente.

Les téléphones d'un même événement se **rafraîchissent entre eux** : chacun voit
le total de tous les postes.

## Compter avec la caméra

Un bouton **📷 Caméra** (à côté de **✋ Manuel**) transforme le téléphone en
compteur automatique : il filme le passage, repère les personnes, les suit et
compte celles qui **franchissent une ligne** tracée à l'écran, avec le sens
(entrée ou sortie). Chaque passage devient un appui comme les autres : même file
d'attente hors ligne, même bouton **Annuler**, mêmes résultats. Le mode manuel
reste disponible à tout moment pour corriger (**+1 / −1**) ou en secours.

**Vie privée** : l'analyse se fait **sur le téléphone**. Les images ne sont ni
enregistrées, ni envoyées au serveur, ni confiées à un service extérieur ; seuls
les nombres et les heures sont conservés. Le détecteur (MediaPipe, licence
Apache-2.0) est hébergé sur le site lui-même (`assets/vendor/mediapipe/`). Prévenez
tout de même les visiteurs si une caméra est visible à l'entrée.

### Installation du téléphone

1. **Ouvrir une première fois le mode caméra avec du réseau** (≈ 17 Mo téléchargés une
   seule fois, puis gardés sur le téléphone : ensuite, plus besoin de réseau).
2. Fixer le téléphone **en hauteur** (2 à 3 m), **de biais ou de face** par rapport au
   passage, de façon à voir les gens en entier. Éviter le contre-jour.
3. **Faire glisser les deux extrémités de la ligne jaune** pour barrer le passage, en
   travers du flux. La flèche **ENTRÉE** montre le sens compté en entrée ; **⇄ Inverser le sens**
   la retourne. Une personne doit traverser la ligne en entier pour être comptée.
4. **Brancher le téléphone** : la détection en continu chauffe et vide la batterie.
5. Contrôler quelques minutes à côté d'un compteur manuel (ou en regardant le total) avant
   de s'y fier, puis corriger avec **+1 / −1** si besoin.

### Ce qu'il faut savoir sur la précision

- Une personne qui en **cache** une autre, ou deux personnes **collées** (couple, parent
  avec enfant), peuvent être vues comme une seule : le comptage automatique **sous-estime**
  dans la foule dense. Pour un passage large ou très fréquenté, mettre **un téléphone par
  portion de passage**, ou compter à la main.
- Il faut au moins **~5 images analysées par seconde**. En dessous de 4, l'écran affiche
  « ⚠ Détection lente » : des passages peuvent être manqués. Les téléphones récents font
  10 images/s ou plus.
- Nécessite un navigateur récent (Chrome / Edge / Safari 16.4 ou plus) et **HTTPS**. Si la caméra
  est refusée ou indisponible, un message l'explique et le mode manuel reste utilisable.
- **Essai de validation** : sur une vidéo publique de piétons (jeu d'essai d'OpenCV, 79 s, des
  personnes qui vont et viennent dans les deux sens), le suivi compte **21 passages**, de façon
  stable de 10 à 5 images analysées par seconde (puis de moins en moins : 15 à 3 images/s, 12 à
  1,7 image/s). Une revue image par image d'une version préliminaire du suivi (aussi 21 passages)
  a montré que presque tous étaient de vrais passages dans le bon sens, avec deux cas douteux
  (un changement d'identité entre deux personnes proches, et des personnes groupées comptées pour
  une seule). **Cet essai ne remplace pas un test sur place**, avec votre téléphone, votre
  éclairage et votre affluence.

## Ce que voit le responsable

- **Résultats** : visiteurs entrés, personnes sur place, **pic de présence** (et son heure), tranche la plus chargée, graphique par tranches de 15 / 30 / 60 min (entrées, sorties, présence, ligne de capacité), détail par poste, actualisation automatique optionnelle. Export **CSV** de la courbe et du détail (s'ouvre directement dans Excel).
- **Corrections** : ajouter des personnes à la main (un compteur oublié), ou tout remettre à zéro.
- **Événements & postes** : créer, modifier, renommer les postes, choisir l'événement « en cours », supprimer.

## Installation et déploiement

Rien à installer ni à migrer : les tables (préfixe `cp_`) se créent toutes
seules au premier accès (`cp_boot()` dans `includes/comptage.php`). Elles
n'affectent pas les tables existantes. `deploy-brocante.sh` n'envoie jamais
`brocante.db` (sauf `--with-db`).

PHP 8.0 ou plus, SQLite (déjà requis par le site). **HTTPS obligatoire** en
production pour le mode hors-ligne (service worker) et l'écran qui reste
allumé — c'est déjà le cas de `https://brocante.arrimage.com`.

## Fichiers

```
includes/comptage.php          logique métier, schéma SQLite, résultats, exports
includes/comptage-layout.php   gabarits admin et page de connexion
includes/qrcode.php            générateur de QR code (SVG), sans service externe
admin/comptage/*.php           résultats, événements & postes, accès, export CSV
comptage/index.php             le compteur (page plein écran)
comptage/api.php               envoi des appuis + totaux (JSON, session + jeton CSRF)
comptage/login.php, logout.php accès par code (5 essais, puis blocage 15 min)
comptage/sw.js, manifest.json  mode hors-ligne et installation sur l'écran d'accueil
assets/comptage.js, .css       comportement et style du compteur (manuel et caméra)
assets/comptage-vision.js      suivi des personnes et franchissement de ligne (sans dépendance, testé sous Node)
assets/vendor/mediapipe/       détecteur de personnes hébergé sur le site (voir son README : origine, licences)
scripts/test-comptage.php      tests de la logique PHP (base jetable, php scripts/test-comptage.php)
scripts/test-comptage-vision.js tests du suivi par caméra, sur trajectoires simulées (node scripts/test-comptage-vision.js)
```

## Limites à connaître

- En **manuel**, la précision dépend des bénévoles (et d'un bon « annuler ») ; en **caméra**, elle dépend du placement du téléphone et de la densité du passage (voir plus haut). Pour un grand site, mettre un compteur par entrée.
- Les sorties ne sont fiables que si elles sont comptées à toutes les sorties ; sinon, désactiver l'option et se contenter du nombre de visiteurs.
- Le mode caméra analyse les images en direct sans les enregistrer. Ne les enregistrez pas non plus avec une autre application.
