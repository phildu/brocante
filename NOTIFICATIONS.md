# Module Notifications

Envoi de messages aux utilisateurs sur leurs canaux préférés : **e-mail, SMS,
WhatsApp, Discord, Telegram, navigateur (Web Push)**. Chaque utilisateur choisit où et sur quels
sujets on peut lui écrire.

- **Responsable** : *Administration → Notifications* (`/admin/notifications/`), selon les droits de son rôle (voir « Droits d'accès »).
- **Utilisateurs** : page personnelle `/notifications/preferences.php?t=…` (lien secret), pour régler ses canaux et rubriques ou se désinscrire.

## Pour démarrer

1. **Canaux & réglages** : renseigner au moins l'e-mail (adresse d'expédition), puis les autres canaux voulus. Le *Test rapide* valide un canal sans créer d'utilisateur.
2. **Utilisateurs** : créer à la main, ou importer les bénévoles de la buvette / les clients ayant payé. Pour chacun : une adresse par canal, un ordre de préférence, les rubriques souhaitées.
3. **Envoyer** : choisir la rubrique, les destinataires et le message ; *Voir qui recevra quoi* avant d'envoyer.
4. **Cron** (recommandé) : voir « Envoi automatique » ci-dessous.

## Canaux

| Canal | Fournisseur | À prévoir |
|---|---|---|
| E-mail | `mail()` de l'hébergement, ou serveur SMTP (SSL / STARTTLS) | adresse d'expédition du domaine du site |
| SMS | Twilio | Account SID, Auth Token, numéro ou *Messaging Service* |
| WhatsApp | Twilio | numéro approuvé par Meta (ou bac à sable Twilio) |
| Discord | webhook (aucun réglage) ou message privé via un bot | jeton du bot pour les MP |
| Telegram | bot Telegram (@BotFather) | jeton ; la personne doit d'abord écrire `/start` au bot |
| Navigateur | Web Push (RFC 8030/8291/8292), sans bibliothèque ni compte tiers | rien : les clés VAPID se créent seules ; HTTPS obligatoire en ligne |

### Navigateur (Web Push)

- **Opt-in par la personne seule** : sur sa page de préférences, le bouton « Activer sur cet appareil » demande l'autorisation du navigateur (jamais avant un clic). L'administration ne peut ni créer ni modifier cet abonnement ; elle peut seulement le suspendre.
- **Un navigateur par utilisateur** : réactiver depuis un autre appareil remplace l'abonnement précédent.
- Clés VAPID dans `.secrets/webpush.json` (0600, hors Git et hors déploiement), créées au premier besoin. **Les supprimer ou les changer invalide tous les abonnements** (à refaire par chaque personne). Préproduction et production ont chacune leur paire.
- L'adresse d'un abonnement est refusée si son hôte n'est pas un service de push connu (Google, Mozilla, Apple, Microsoft) : le serveur n'appelle jamais une URL quelconque.
- Abonnement retiré par le navigateur (HTTP 404/410) : le canal est coupé automatiquement.
- Le message tient en ~240 caractères (titre = objet, texte = version courte ou corps). Le clic ouvre la page d'accueil du site.
- iPhone/iPad : Web Push uniquement si le site est ajouté à l'écran d'accueil ; la page l'explique.
- Le service worker (`pwa-sw.php`) porte les écouteurs `push` et `notificationclick`.

Les identifiants sont dans `.secrets/notifications.json` (droits 0600, jamais
dans la base ni dans Git, exclus du déploiement comme les clés Stripe/Gemini).
Ils ne sont jamais réaffichés ; un champ secret laissé vide conserve la valeur.

**WhatsApp** : un message libre n'est possible que dans les 24 h suivant la
dernière réponse de la personne ; au-delà il faut un modèle approuvé par Meta
(non géré : l'envoi échoue avec un message explicite).

## Utilisateurs, rubriques, préférences

- Une **rubrique** classe les messages. D'origine : suivi de commande, événements, bénévolat, dons & invendus (*suivi*) et actualités (*marketing*). On peut en créer.
- **Suivi** : reçu par défaut sur les canaux actifs (chacun peut s'en retirer). **Marketing** : jamais envoyé sans abonnement explicite (opt-in) ; le caractère marketing d'une rubrique ne peut pas être modifié après création (cela abonnerait tout le monde sans consentement).
- Les préférences sont une matrice rubrique × canal. Seuls les écarts à la valeur par défaut sont stockés ; la ligne d'un opt-in garde sa date et son origine (`admin` / `utilisateur`) comme preuve de consentement.
- Les imports (bénévoles, clients) ne créent **aucun** abonnement marketing.
- La page publique ne permet pas de modifier les adresses : un lien transféré par erreur ne peut pas rediriger les messages. Elle propose « plus d'actualités ni d'offres » et « ne plus rien recevoir ».
- Supprimer un utilisateur efface ses coordonnées, préférences **et historique d'envois**.

## Envoi

- Variables dans le texte : `{prenom}` `{nom}` `{site}` `{lien_preferences}`.
- *Version courte* facultative pour SMS / WhatsApp / Discord / Telegram ; sinon titre + texte complet.
- **Stratégie** : *tous les canaux* de la personne, ou *canal préféré puis secours* (le suivant n'est utilisé que si le précédent échoue définitivement).
- **Messages marketing** : lien de désinscription ajouté automatiquement ; e-mails avec `List-Unsubscribe` + désinscription en un clic (RFC 8058) ; SMS/WhatsApp différés hors des **heures calmes** (20 h–8 h et dimanche par défaut, réglable). Les jours fériés ne sont pas gérés : c'est une aide, pas un avis juridique.
- Une désinscription ou un canal coupé **entre la mise en file et l'envoi** est revérifié : l'envoi est annulé.
- Erreurs temporaires (réseau, 429, 5xx, SMTP 4xx) : 4 tentatives (après 1 min, 10 min, 1 h). Erreurs définitives (numéro invalide…) : échec immédiat. Un STOP Twilio ou un bot Telegram bloqué désactive le canal de la personne.

## Envoi automatique (cron)

Les petits envois partent tout de suite ; reprises, envois différés et gros
volumes passent par une file vidée par :

```bash
php /chemin/vers/scripts/notifications-worker.php --quiet
```

À programmer toutes les 5 minutes (tâche CRON OVH ou `crontab -e`). Options :
`--limit=200`, `--budget=50` (secondes), `--purge-days=90`. Il purge aussi
l'historique de plus de 90 jours. Sans cron, le bouton *Traiter la file* du
tableau de bord fait le même travail.

## Utilisation depuis le reste du site

```php
require_once __DIR__ . '/includes/notifications.php';

// $userId : id d'un utilisateur du module (nt_users)
nt_notify('commande', [$userId], 'Votre colis est parti',
          "Bonjour {prenom}, votre commande a été expédiée.");
```

Retourne le nombre d'envois mis en file et les exclusions (canal coupé,
non abonné…). Options : `strategy` (`all`|`fallback`), `channels`,
`short_body`, `immediate` (`false` = file seule).

### Déjà branché : commande expédiée

Quand, dans *Administration → Commandes*, une commande **payée** passe à
« Expédiée » (`admin/update-order.php` → `nt_notify_order_shipped()`), le client
est prévenu sur ses canaux (rubrique « Suivi de commande ») avec la date de sa
commande, ses articles et le numéro de suivi s'il est saisi.

- Le client est retrouvé par son e-mail, ou créé automatiquement (nom pris dans l'adresse de livraison) avec le seul canal e-mail. Il n'a aucun abonnement marketing.
- **Une seule notification par commande** : corriger ensuite le numéro de suivi, ou repasser la commande en « À préparer » puis « Expédiée », n'envoie rien de plus tant qu'une première notification est partie (ou en file).
- Les préférences du client sont respectées : s'il s'est retiré du suivi de commande, ou a coupé son canal, rien n'est envoyé — la commande n'est alors pas marquée notifiée.
- Sans adresse de livraison (retrait à l'atelier), le message dit « est prête » au lieu de « a été expédiée ».
- Un échec d'envoi (e-mail non configuré, panne du serveur de messagerie…) **ne bloque jamais** la mise à jour de la commande : le résultat s'affiche dans le message de confirmation, et les envois non partis sont repris par le cron.
- Le texte du message est dans `nt_notify_order_shipped()` (`includes/notifications.php`).

### Déjà branché : fermetures exceptionnelles

Les horaires d'ouverture, jours fériés, congés et fermetures exceptionnelles se saisissent dans *Administration → Contenu des pages →
Horaires & fermetures* ; le site les annonce déjà en bandeau (avertissement pendant la fermeture, information dans les 7 jours qui la
précèdent). Chaque période enregistrée propose en plus **« Prévenir les clients… »** (`admin/closure-alert.php`, `includes/closure-alerts.php`) :

- **Jamais automatique** : à l'enregistrement d'une nouvelle fermeture à venir, un lien propose de prévenir les clients ; rien ne part sans
  relecture du message et confirmation. Une fermeture terminée ne peut plus être annoncée.
- **Message prérempli** (objet, texte, version courte pour SMS/WhatsApp) avec la période en toutes lettres, le motif et la date de réouverture,
  modifiable avant l'envoi ; variables `{prenom}` `{site}` `{lien_preferences}`.
- **Public** au choix : clients ayant commandé, inscrits à la lettre d'information, contacts ajoutés à la main. Les bénévoles de la buvette n'en
  font jamais partie. Les contacts manquants sont créés dans le module à l'envoi (`nt_import_customers()`, `nt_import_newsletter()`), **sans abonnement
  marketing** ; le nombre de contacts concernés est affiché avant l'envoi.
- **Rubrique « Fermetures exceptionnelles »** (`fermeture`, créée au premier envoi, non marketing) : chaque personne la reçoit par défaut mais peut
  s'en retirer depuis sa page de préférences ; ce choix est respecté.
- **Un seul message par personne**, sur son canal préféré (stratégie « canal préféré puis secours »).
- **Pas de double envoi** : une période déjà annoncée affiche « Clients prévenus le … » et n'est renvoyée que sur demande explicite (« Renvoyer quand même »).
- **Droit exigé** : Notifications · Envoyer (la page liste des personnes et leurs coordonnées) ; sans canal configuré, le bouton d'envoi reste inactif.
- Journal des alertes : réglage `opening_closure_alerts` (une entrée par période Du/Au). Test : `php scripts/test-closure-alerts.php`.

## Droits d'accès

Le module s'appuie sur les rôles et droits fins du back-office (`includes/auth.php`) : ressource **Notifications** (niveau Portail), avec les droits Consulter, Créer, Modifier, Supprimer et **Envoyer**. Le propriétaire a tous les droits.

| Pour… | Droit exigé |
|---|---|
| Voir le tableau de bord, les utilisateurs, rubriques et l'historique | Notifications · Consulter |
| Créer utilisateurs / rubriques, importer bénévoles et clients | Notifications · Créer |
| Modifier utilisateurs, préférences, rubriques | Notifications · Modifier |
| Supprimer un utilisateur (effacement), une rubrique, purger l'historique | Notifications · Supprimer |
| Envoyer un message (même l'aperçu), traiter la file, relancer un envoi, tester un canal | Notifications · Envoyer |
| Voir ou modifier les **réglages des canaux** (clés API, mots de passe SMTP, jetons) | **Clés API** (droit existant, comme Stripe ou Gemini) |

- Le droit Notifications donne accès aux **coordonnées** des clients et bénévoles : il n'est donc pas inclus dans les rôles de lecture seule proposés au départ.
- Comme partout ailleurs, toute action non répertoriée dans `auth_action_map()` est refusée, et `scripts/test-auth.php` vérifie que chaque action du module y figure.
- La page publique de préférences (`/notifications/preferences.php?t=…`) et le script cron n'ont pas de compte : le premier s'ouvre par lien secret, le second en ligne de commande seulement.

## Installation et déploiement

Rien à migrer : les tables (préfixe `nt_`) se créent au premier accès
(`nt_boot()`). `deploy-brocante.sh` n'envoie ni `brocante.db` ni `.secrets/` :
utilisateurs, historique et clés saisis en ligne ne sont pas écrasés.
PHP 8.0+, SQLite, cURL (déjà requis par le site), mbstring.

## Fichiers

```
includes/notifications.php          logique métier, schéma, pilotes de canaux
includes/webpush.php                Web Push : chiffrement RFC 8291, clés VAPID, validation des abonnements
includes/closure-text.php           clé, période et message d'une fermeture (commun aux boutiques et aux galeries)
includes/closure-alerts.php         alerte de fermeture aux clients (message, public, envoi, journal)
admin/closure-alert.php             page de confirmation et d'envoi de l'alerte de fermeture
includes/notifications-layout.php   gabarits admin et page publique
admin/notifications/*.php           pages du responsable
notifications/preferences.php       page publique de préférences
scripts/notifications-worker.php    cron : file d'envoi + purge
scripts/test-notifications.php      tests
scripts/test-closure-alerts.php     tests de l'alerte de fermeture
assets/notifications.css            styles
```

## Tests

```bash
php scripts/test-notifications.php
```

Base et configuration jetables ; aucun appel réseau (les API et `mail()` sont
remplacés par des doubles, le client SMTP est testé contre un faux serveur
local). Couvre adresses, préférences et consentement, heures calmes, pilotes,
reprises, canal de repli, désinscription pendant la file, import, effacement.

## Limites connues

- Pas de réception : les réponses des utilisateurs et les accusés de lecture ne sont pas traités (hors STOP Twilio, géré à l'envoi suivant).
- Pas d'accusé de livraison final : « envoyé » signifie accepté par le fournisseur.
- STARTTLS/SSL du client SMTP non testé automatiquement (seul le dialogue en clair l'est) : tester avec le *Test rapide*.
- Le lien personnel de préférences est un secret porté par l'URL ; il est envoyé en clair dans les messages marketing.
- Qui détient le lien personnel peut abonner *son* navigateur et recevoir les notifications de cette personne (au même titre qu'il peut déjà la désinscrire). Même risque que pour le reste du lien ; ne le transférez pas.
