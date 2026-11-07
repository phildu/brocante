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

## Préférences de l'utilisateur

Réponses en français, courtes et sans jargon ; pas de terminal à lui demander quand une action peut passer par l'application.
