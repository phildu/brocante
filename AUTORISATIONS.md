# Autorisations fines — collaborateurs, rôles et droits

Jusqu'ici, un seul mot de passe donnait accès à tout l'administration. Désormais :

- chaque **collaborateur** a son compte (e-mail + mot de passe) ;
- il a **un rôle** ;
- un **rôle** est un ensemble de **droits** choisis par le propriétaire, par **niveau** et par **ressource** : *consulter, créer, modifier, supprimer*, plus quelques droits spécifiques (IA, masquage, prix d'achat, export).

Le mot de passe principal existant (`.secrets/admin_password.txt`) reste valable : avec le champ e-mail **vide**, il ouvre une session « Propriétaire ». Rien à migrer.

## Où ça se règle

*Administration → Équipe* (`/admin/users.php`), deux onglets :

| Onglet | Rôle |
|---|---|
| **Collaborateurs** | Inviter (mot de passe provisoire, à changer à la première connexion), changer le rôle, désactiver / réactiver, réinitialiser le mot de passe, supprimer. |
| **Rôles et droits** | Créer / dupliquer / modifier / supprimer un rôle, avec la matrice de droits ci-dessous. |

Chacun voit ses propres droits et change son mot de passe dans **Mon compte** (`/admin/account.php`).

## Les trois niveaux

| Niveau | Ressources (droits au-delà de consulter / créer / modifier / supprimer) |
|---|---|
| **Portail** | Tableau de bord · Réglages du site · Clés API · Collaborateurs · Rôles et droits · Module Buvette · Module Invendus & dons (*exporter*) |
| **Galerie** | Photos des pièces (*IA*) · Médiathèque (*IA*) · Diaporama d'accueil · Diaporama boutique · Bandeaux de page |
| **Boutique** | Catalogue (*masquer/réafficher*, *prix d'achat*) · Import par lot (*IA*) · Commandes · Transporteurs et frais de port |

Un droit s'écrit `niveau.ressource.action`, par exemple `boutique.produits.supprimer`.
« Consulter » est coché d'office dès qu'un autre droit l'est sur la même ressource.

> Le découpage en trois niveaux est modifiable en un seul endroit : `auth_catalog()` dans `includes/auth.php`. Ajouter une ressource ou une action = ajouter une ligne ; l'écran des rôles, le menu et les tests suivent.

## Rôles proposés au premier démarrage

Modifiables et supprimables (sauf Propriétaire) :

- **Propriétaire** — tous les droits, y compris ceux ajoutés plus tard. Non modifiable.
- **Gestionnaire de boutique** — catalogue, commandes, photos des pièces. Sans clés API, équipe, prix d'achat.
- **Préparateur de commandes** — voit et met à jour les commandes.
- **Photographe / visuels** — toute la galerie, catalogue en lecture seule.
- **Bénévole d'événement** — buvette et invendus & dons, sans suppression.
- **Lecture seule** — consulte tout, hors clés API, équipe et commandes (données clients).

## Règles de sécurité

- **Refus par défaut.** Une page ou une action non répertoriée est refusée ; un droit mal orthographié lève une erreur au lieu d'ouvrir ou fermer un accès en silence.
- **Effet immédiat.** Les droits sont relus à chaque requête : retirer un droit à un rôle ou désactiver un compte coupe l'accès sans attendre la déconnexion de la personne.
- **Pas d'escalade.** On ne peut accorder (à un rôle, ou en attribuant un rôle) que des droits qu'on possède soi-même ; on ne peut pas modifier, désactiver ou supprimer un compte ou un rôle qui nous dépasse, ni toucher à son propre rôle ou se supprimer.
- **Rôle utilisé = non supprimable.** Il faut d'abord réaffecter ses collaborateurs.
- **Dernier propriétaire.** Si aucun mot de passe principal n'est configuré, on ne peut pas supprimer / désactiver / rétrograder le dernier compte propriétaire actif.
- **Connexion.** Mots de passe hachés (`password_hash`), 10 caractères minimum, 8 essais ratés / 15 min / IP avant blocage, session régénérée à la connexion, message d'erreur identique que le compte existe ou non, CSRF sur les écrans d'équipe.
- Le masquage des boutons dans les pages (formulaires en lecture seule ou cachés) n'est qu'un **confort** : la protection est toujours côté serveur.

## Amorçage et dépannage

Sans mot de passe principal configuré, personne ne peut se connecter. Créer ou récupérer un compte propriétaire, **sur le serveur** :

```bash
php scripts/create-owner.php vous@exemple.fr "Votre nom"
```

Un mot de passe provisoire est généré et affiché une fois. Si le compte existe déjà, son mot de passe est réinitialisé et il est réactivé.

> Correctif au passage : l'ancienne connexion acceptait un mot de passe vide quand `.secrets/admin_password.txt` était absent. Un mot de passe principal vide n'ouvre plus jamais l'accès.

## Installation

Rien à migrer : les tables `admin_*` se créent toutes seules au premier accès (`auth_boot()`), comme celles du tableau de bord et de la buvette ; `schema.sql` en garde une copie. `brocante.db` n'est jamais écrasé par le déploiement.

PHP 8.0 ou plus, SQLite (déjà requis).

## Fichiers

```
includes/auth.php          catalogue des droits, schéma, connexion, rôles, collaborateurs, gardes de page
includes/team-layout.php   gabarit des écrans Équipe + matrice des droits
includes/admin-nav.php     menu filtré selon les droits
admin/users.php, users-action.php     collaborateurs
admin/roles.php, roles-action.php     rôles et droits
admin/account.php                      mon compte, changement de mot de passe
admin/login.php                        connexion (e-mail + mot de passe)
scripts/create-owner.php   amorçage / récupération d'un compte propriétaire
scripts/test-auth.php      tests
```

## Brancher une nouvelle page ou action

- **Page** : `require_permission('niveau.ressource.lire');` en tête (plusieurs droits = au moins un).
- **Script d'action (POST)** : ajouter ses actions dans `auth_action_map()` puis `auth_require_action(basename(__FILE__), (string) ($_POST['action'] ?? ''));`.
- **Endpoint AJAX** : `require_permission_json(...)` (réponse 403 en JSON).
- **Masquer un contrôle** : `<?php if (auth_can('boutique.produits.cout')): ?> … <?php endif; ?>`, ou `auth_ui_gate('mon-action.php')` avant `</body>` pour adapter tous les formulaires d'un script.
- `require_admin()` est désormais réservé au **propriétaire** (refus par défaut).

## Tests

```bash
php scripts/test-auth.php
```

Base jetable, ne touche jamais à `brocante.db`. Vérifie le catalogue, que **chaque page /admin/ est protégée** et que **chaque action de chaque script est répertoriée**, la connexion, les mots de passe, l'effet immédiat des changements, toutes les règles anti-escalade, la limitation des essais.

## Limites connues

- Les modules **Buvette** et **Invendus & dons** sont gérés par ressource entière (consulter / créer / modifier / supprimer), pas par onglet.
- Le tableau de bord est un seul bloc : qui le consulte voit aussi les marges et frais.
- L'espace bénévoles `/buvette/` et les liens secrets des dons gardent leur propre accès (code partagé, jeton) — hors de ce système.
- Pas de journal d'audit (qui a fait quoi) ni de double authentification pour l'instant.
- Les écrans d'administration existants hors équipe n'avaient pas de jeton CSRF avant ce changement et n'en ont toujours pas (hors tableau de bord et Invendus).
