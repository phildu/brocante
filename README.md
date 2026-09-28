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
2. sinon le fichier `.tenant` à la racine (écrit sur le serveur par le déploiement) ;
3. sinon `petit-chalet`.

Le Petit Chalet garde ses emplacements historiques (`brocante.db`, `.secrets/`,
`assets/logo.png`) : le site en ligne et `config.local.php` fonctionnent comme avant.
Les autres commerces ont leur base dans `data/<slug>.db` et leurs clés dans
`.secrets/<slug>/`.

## Créer un nouveau commerce

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
