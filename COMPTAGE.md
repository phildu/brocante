# Module Comptage de foule — vide-grenier

Compter les visiteurs d'un vide-grenier depuis un smartphone : un gros bouton
**+1** à l'entrée, un bouton **sortie**, et le responsable suit en direct le
nombre de visiteurs, la fréquentation en temps réel, le pic de la journée et
la courbe heure par heure.

- **Responsable** : *Administration → Comptage de foule* (`/admin/comptage/`), même mot de passe que le reste de l'admin.
- **Bénévoles aux entrées** : `/comptage/`, depuis un téléphone, avec un **code partagé**. Aucun compte, aucune donnée personnelle : on ne mémorise que des nombres et des heures.

## Pour démarrer

1. *Comptage de foule → Événements & postes* : créer l'événement (nom, date, lieu, capacité d'accueil si le site est limité). Un poste « Entrée principale » est créé ; ajouter un poste par entrée supplémentaire (parking, côté église…).
2. *Accès bénévoles* : définir un **code**, envoyer le lien et le code aux bénévoles.
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
admin/comptage/*.php           résultats, événements & postes, accès, export CSV
comptage/index.php             le compteur (page plein écran)
comptage/api.php               envoi des appuis + totaux (JSON, session + jeton CSRF)
comptage/login.php, logout.php accès par code (5 essais, puis blocage 15 min)
comptage/sw.js, manifest.json  mode hors-ligne et installation sur l'écran d'accueil
assets/comptage.js, .css       comportement et style du compteur
scripts/test-comptage.php      tests de la logique (base jetable, php scripts/test-comptage.php)
```

## Limites à connaître

- C'est un **comptage manuel** : sa précision dépend des bénévoles (et d'un bon « annuler »). Pour un grand site, mettre un compteur par entrée.
- Les sorties ne sont fiables que si elles sont comptées à toutes les sorties ; sinon, désactiver l'option et se contenter du nombre de visiteurs.
- Ne pas photographier ni filmer les visiteurs : l'application n'en a pas besoin.
