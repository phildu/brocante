# Boutique en marque blanche

Boutique en ligne PHP + SQLite (sans framework ni serveur de base de données),
créée pour **La Brocante du Petit Chalet** et réutilisable pour n'importe quel
commerce. Tout ce qui est propre à un commerce est regroupé dans :

```
tenants/<slug>/tenant.php        nom, URL, mot de passe admin, couleurs, catégories,
                                 textes de l'accueil, contexte pour l'IA, base SQLite
tenants/<slug>/seed-data.json    contenu initial : accueil, histoire, contact, produits
assets/tenants/<slug>/           logos
```

Commerces fournis :

| Dossier                        | Rôle                                                          |
|--------------------------------|---------------------------------------------------------------|
| `tenants/petit-chalet`         | La Brocante du Petit Chalet (site actuel, inchangé)           |
| `tenants/exemple-librairie`    | Exemple de second commerce (couleurs, rayons et textes propres) |
| `tenants/_modele`              | Modèle copié pour chaque nouveau commerce                     |

## Quel commerce est affiché ?

1. la variable d'environnement `TENANT` ;
2. sinon le sous-domaine, s'il porte le nom d'un commerce (`naty.brocenstock.test` → `tenants/naty`) ;
3. sinon le fichier `.tenant` à la racine (écrit sur le serveur par le déploiement) ;
4. sinon `petit-chalet`.

Le Petit Chalet garde ses emplacements historiques (`brocante.db`, `.secrets/`,
`assets/logo.png`) : le site en ligne et `config.local.php` fonctionnent comme avant.
Les autres commerces ont leur base dans `data/<slug>.db` et leurs clés dans
`.secrets/<slug>/`.

## Tester en local avec Herd : une adresse par commerce

`herd link brocenstock` dans ce dossier, puis :

| Adresse                                     | Affiche                                        |
|---------------------------------------------|------------------------------------------------|
| http://brocenstock.test/portail/            | le portail des commerces                       |
| http://naty.brocenstock.test                | le commerce `tenants/naty`                     |
| http://exemple-librairie.brocenstock.test   | la Librairie des Quais                         |
| http://brocenstock.test                     | le commerce par défaut (`.tenant`, sinon Petit Chalet) |

Le sous-domaine est l'identifiant du commerce (nom de son dossier dans
`tenants/`) ; un sous-domaine inconnu affiche le commerce par défaut.

Le portail liste les commerces avec leur adresse, affiche la boutique choisie
(accueil, boutique, panier, administration, vue mobile), réinitialise les
données de démonstration et crée un nouveau commerce depuis un formulaire
(identité, logo, couleurs, catégories, textes, premiers produits). Il écrit
dans le dossier du projet : il ne répond qu'en local (hôte `.test`,
`localhost` ou `127.0.0.1`) et n'est jamais déployé. Pensez à ajouter à git
les dossiers `tenants/<slug>` et `assets/tenants/<slug>` qu'il crée.

## Créer un nouveau commerce en ligne de commande

```bash
scripts/new-tenant.sh boulangerie-martin "Boulangerie Martin"
# puis adapter tenants/boulangerie-martin/tenant.php et seed-data.json,
# et remplacer assets/tenants/boulangerie-martin/logo.png
```

## Essayer un commerce (session cloud ou Mac)

```bash
ADMIN_PASSWORD=essai scripts/cloud-setup.sh exemple-librairie
# → http://127.0.0.1:8000  (administration : /admin/, mot de passe « essai »)
```

Le script crée la base du commerce si elle n'existe pas (`schema.sql` +
`seed-data.json`) puis lance le serveur PHP intégré. Une base existante n'est
jamais écrasée. Pour recharger les données de démonstration :
`TENANT=exemple-librairie php seed.php`.

## Déployer sur OVH

```bash
./deploy-brocante.sh                                  # Petit Chalet (.env.deploy)
./deploy-brocante.sh --tenant exemple-librairie       # autre commerce (.env.deploy.exemple-librairie)
./deploy-brocante.sh --tenant exemple-librairie --with-db   # premier déploiement : envoie aussi la base
```

Chaque commerce a son propre fichier d'identifiants FTP `.env.deploy.<slug>`
(même format que `.env.deploy`). Seule la configuration du commerce déployé est
envoyée sur son serveur, avec un fichier `.tenant` qui le désigne. Le dossier
`uploads/` n'est envoyé que si `deploy_uploads` vaut `true` dans son `tenant.php`
(c'est le cas du Petit Chalet), pour ne pas copier ses photos chez les autres.
