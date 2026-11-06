# Déploiement : local → préprod → prod

Document de travail (jamais envoyé en ligne). Rien ne se déploie sans commande explicite.

```
  Mac (Herd, *.test)  ──►  PRÉPROD (OVH)  ──►  PROD (OVH)
  développer, tester       valider en vrai      clients réels
  main + CI verte          tag preprod-…        tag prod-…
```

## Les trois environnements

| | local | préprod | prod |
|---|---|---|---|
| Adresse | `http://brocante.test` (Herd) | `https://brocs.arrimage.com` | à définir (`URL_FRONT` de `.env.deploy.portail.prod`) |
| `APP_ENV` | `local` (présence de `config.local.php`) | `preprod` (`.app-env` déposé par le script) | `prod` (`.app-env` déposé par le script) |
| Données | bases `*.db` locales, jetables | bases propres à la préprod | **données réelles** : jamais écrasées |
| Paiements | clés de test | clés de **test** uniquement | clés **live**, saisies sur le serveur |
| Moteurs de recherche | `noindex` | `noindex` + `robots.txt` « Disallow: / » | indexable |
| Accès de déploiement | — | `.env.deploy.portail` (ou `.env.deploy.<slug>`) | `.env.deploy.portail.prod` (ou `.env.deploy.<slug>.prod`) |
| Dossier serveur | — | ex. `/www/brocs/` | **autre dossier** (le script refuse le même) |

Un serveur sans `.app-env` est traité comme **préprod** (jamais comme prod) : `includes/app-env.php`.
`/health.php` annonce l'environnement (`"env":"prod"`) ; les contrôles s'en servent pour détecter un mauvais dossier.

## Cycle de travail

1. **Local** : développer sur une branche, tester sur `*.test`, ouvrir une PR vers `main`. La CI
   (`.github/workflows/ci.yml` : syntaxe PHP, PHPUnit, secrets) doit être verte avant fusion.
2. **Préprod** : depuis `main`, arbre propre :
   ```bash
   scripts/release.sh preprod --portail --dry-run   # liste ce qui partirait, n'écrit rien
   scripts/release.sh preprod --portail
   ```
   Enchaîne : pré-vol → déploiement → `scripts/smoke-remote.sh` → tag local `preprod-AAAA-MM-JJ-HHMM`.
   Valider ensuite à la main sur la préprod (parcours achat, admin, mails, mobile).
3. **Prod** : seulement un commit déjà passé en préprod (le tag `preprod-*` doit être sur `HEAD`) :
   ```bash
   scripts/release.sh prod --portail --dry-run
   scripts/release.sh prod --portail
   ```
   Enchaîne : pré-vol → **sauvegarde des bases de la prod** (`backups/prod/…`) → déploiement (confirmation à taper : `PROD`)
   → contrôle → tag `prod-AAAA-MM-JJ-HHMM`. Pousser les tags : `git push origin <tag>`.

`scripts/release.sh status` résume les accès présents et les derniers tags. `scripts/release.sh check` = pré-vol seul.

### Garde-fous (dans `deploy-brocante.sh --env prod`)

- fichiers suivis non commités → refus ; `HEAD` sans tag `preprod-*` → refus ;
- `.env.deploy.*.prod` visant le même serveur et dossier que la préprod → refus ;
- `--with-db` interdit en prod (écraserait commandes et données) ; `--first-install` réservé à un seul commerce (hors `--portail` :
  les bases absentes sont créées à la première visite) ;
- confirmation `PROD` au clavier, terminal interactif obligatoire ; `scripts/release.sh prod` exige la branche `main`.
- **Correctif urgent** : `--skip-preprod-check` saute seulement le contrôle « déjà en préprod ». Déployer ensuite la même
  version en préprod pour rattraper l'écart, et noter ici la date et la raison.

## Première mise en prod : liste de contrôle

- [ ] **OVH** : domaine de prod créé, Hébergement → Multisite sur un dossier **dédié** (ex. `/www/prod/`), SSL actif, redirection HTTPS.
- [ ] `cp deploy/env.prod.example .env.deploy.portail.prod`, remplir, `chmod 600`. Mots de passe FTP et portail **différents** de la préprod.
- [ ] `scripts/release.sh prod --portail --dry-run` puis déploiement réel (les bases se créent à la première visite).
- [ ] `scripts/smoke-remote.sh https://<domaine-prod> prod` au vert ; ouvrir `/portail/` et se connecter.
- [ ] **Paiements** : saisir les clés **live** (Stripe, et Revolut/PayPal/Monetico si utilisés) depuis l'administration de la prod ; déclarer les
  webhooks côté prestataire avec l'adresse de prod (`stripe-webhook.php`, `revolut-webhook.php`, `monetico-ipn.php`) ; un paiement réel
  de faible montant puis remboursement.
- [ ] **Clés d'IA partagées** : portail → « Clés API partagées » → *Mise en production* (taper `PRODUCTION`) : chaque commerce n'utilise plus que les siennes.
- [ ] **Messages sortants** (module Notifications : e-mail, SMS, WhatsApp, Discord, Telegram) : identifiants de prod saisis ; vérifier qu'aucun
  destinataire de test n'est resté configuré.
- [ ] **Supervision** : sonde externe (UptimeRobot ou équivalent) sur `https://<domaine-prod>/health.php` (503 = problème).
- [ ] **Sauvegardes** : `scripts/backup-remote-db.sh prod --portail` (fait avant chaque déploiement) ; planifier aussi une sauvegarde régulière
  (hebdomadaire au minimum) et garder une copie hors du Mac.
- [ ] **Tâches planifiées** : `scripts/` n'est pas envoyé en ligne. Vérifier comment tournent `notifications-worker.php`, `plugins-cron.php`,
  `beta-reminders.php`, `feedback-reminders.php`, `connectors-sync.php` sur le serveur de prod (cron OVH) avant d'ouvrir au public.
- [ ] Mentions légales, CGV, RGPD (registre, cookies) relues pour le vrai domaine.

## Retour arrière

- **Code** : revenir au tag précédent puis redéployer. Le commit visé a normalement déjà son tag `preprod-*` :
  ```bash
  git switch --detach prod-AAAA-MM-JJ-HHMM
  ./deploy-brocante.sh --env prod --portail
  git switch main
  ```
- **Base** : les schémas évoluent par ajout (`*_ensure_schema`), un ancien code tolère généralement une base plus récente. En cas de corruption,
  restaurer la copie `backups/prod/<horodatage>/` (écrire le fichier `.db` sur le serveur, opération manuelle, après avoir sauvegardé l'état courant).

## Données et secrets

- Les données ne circulent que **prod → préprod** (jamais l'inverse), et seulement après anonymisation des données personnelles (RGPD).
- Local → préprod : contenu de test via Administration → *Envoyer en préprod* ; `--with-db` en préprod uniquement.
- Secrets : jamais dans git (`.env.deploy.*`, `.secrets/`, `config.local.php` sont ignorés et exclus du déploiement). Les clés de chaque
  environnement se saisissent depuis son administration ; les clés **Stripe ne sont jamais partagées** entre commerces.

## Pas encore en place

- Protection d'accès (mot de passe HTTP) de la préprod : elle est seulement masquée aux moteurs de recherche.
- Déploiement automatique depuis la CI : volontairement absent, le déploiement reste une action manuelle depuis le Mac.
- Bannière visuelle « PRÉPROD » dans les pages.
