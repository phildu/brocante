# Brocante — consignes communes à toutes les sessions

Complète `../CLAUDE.md` (règles du dossier « web local »). Détail du circuit de mise en ligne : `DEPLOIEMENT.md`.

## Livrer un travail (sessions de développement)

- Travailler sur **une branche à part**, jamais en commitant directement sur `main`. Le dossier est partagé par plusieurs
  sessions : ne pas changer la branche ni les fichiers d'une autre session ; pour travailler isolé, utiliser un `git worktree`.
- Terminer : `vendor/bin/phpunit` vert, commit, push, **PR vers `main`**, CI verte.
- **Ne pas fusionner, ne pas déployer** de sa propre initiative. Conclure par : « PR n° X prête, CI verte » + ce qu'il faut
  tester en préprod (et ce qui n'a pas pu être essayé en local, ex. paiement réel).
- Les fichiers d'un commerce d'essai local (`petit-chalet`, `la-fripe-a-naty`, etc.) ne sont jamais déployés par le script :
  le préciser dans la PR si le travail les touche.

## Mettre en ligne (session « workflow » ou sur demande explicite de l'utilisateur)

1. Fusionner les PR prêtes (sur demande), `git switch main && git pull`, arbre propre.
2. Préprod : `scripts/release.sh preprod --portail` (autorisé sans confirmation). Elle contrôle le site et pose le tag `preprod-…`.
3. L'utilisateur valide la préprod.
4. Prod : `scripts/release.sh prod --portail --confirm-prod`. La confirmation est le **clic d'approbation** de l'application sur la
   commande exacte (règle `ask` de `.claude/settings.local.json`) ; ne jamais contourner cette carte. Sauvegarde des bases
   automatique avant l'envoi. Ne jamais utiliser `--with-db` en prod.
5. Pousser les tags (`git push origin <tag>`) et vérifier `/health.php` de `https://www.bricbrok.com`.

Adresses : préprod `https://pre.bricbrok.com`, prod `https://www.bricbrok.com`. Ne jamais lire ni afficher les identifiants
FTP ou les journaux qui les contiennent (`.env.deploy.*`) ; supprimer les journaux de déploiement bruts après lecture.

## Échange boutiques / galeries entre environnements (à tenir à jour à chaque évolution)

Le portail → « Échange prod / préprod » (`includes/exchange.php`) copie boutiques et galeries entre prod, préprod et local. Il ne transporte
que ce qui est listé (liste blanche) : tout ajout au projet est donc **absent de l'échange tant qu'on ne l'y met pas**. Quand ton travail
ajoute une table SQL, une clé de `tenant.php`, une clé de galerie (`gallery_normalize()`), un réglage de la table `settings` ou un fichier
annexe de galerie (`data/galeries/<id>.xxx`) :

1. Décider s'il voyage (contenu de présentation : oui ; commandes, clients, comptes, secrets, abonnements, données personnelles : jamais).
2. S'il voyage : l'ajouter à la constante de `includes/exchange.php` (`EXCHANGE_SHOP_TABLES`, `EXCHANGE_SHOP_CONFIG_KEYS`, `EXCHANGE_GALLERY_KEYS`,
   `EXCHANGE_SETTINGS_ALLOWED`, `EXCHANGE_GALLERY_FILES_CARRIED`) ; un fichier annexe de galerie demande aussi de l'écrire dans
   `exchange_gallery_export()` / `exchange_gallery_import()`.
3. Dans tous les cas : `php scripts/exchange-baseline.php`, puis commiter `tests/fixtures/exchange-baseline.json`.
   `tests/ExchangeGuardTest.php` fait échouer la CI sinon. Une nouvelle colonne d'une table qui voyage n'exige rien : elle est reprise toute seule.
4. Si le format du paquet change de façon incompatible, incrémenter `EXCHANGE_FORMAT`.

## Préférences de l'utilisateur

Réponses en français, courtes et sans jargon ; pas de terminal à lui demander quand une action peut passer par l'application.
