# Messagerie du portail (contact@bricbrok.com)

Les messages de la plateforme (inscriptions, reçus, équipe, retours, mots de passe oubliés du portail) partent de
`contact@bricbrok.com` par le **SMTP authentifié d'OVH** (`ssl0.ovh.net`, port 465) au lieu de `mail()`.

## Ce qui est automatique

- **Création de la boîte** : *Portail → API & réseaux → Messagerie → Créer / vérifier la boîte*. Appelle l'API OVH
  (`POST /email/domain/bricbrok.com/account`), génère un mot de passe aléatoire et le range dans `.secrets/mailer.json`
  (0600, jamais déployé, jamais réaffiché). Idempotent : si la boîte existe et que le mot de passe est connu, rien n'est fait.
  *Changer le mot de passe* en génère un nouveau (`…/changePassword`).
- **Envoi** : `includes/portal-mailer.php` est appelé par `mail_open_send()` (donc `saas_mail`, `password_reset_mail`…),
  `eq_mail()` et `feedback_mail()`. Il ne prend en charge que les messages dont l'expéditeur est du domaine du portail ;
  l'adresse d'expédition devient `contact@bricbrok.com` (OVH refuse un autre expéditeur), le nom affiché est conservé.
- **Repli** : si le SMTP échoue ou si la messagerie est inactive, le message repart par `mail()` comme avant.
- **Hors périmètre** : les boutiques qui écrivent depuis leur propre domaine, et le module Notifications (son propre SMTP, réglé dans son admin).

## Prérequis chez OVH

1. Offre e-mail **MX Plan** sur `bricbrok.com` (e-mail inclus avec l'hébergement ou offre « E-mail »). *Email Pro* et *Exchange*
   n'utilisent pas ces routes : créer la boîte à la main dans l'espace client, puis saisir son mot de passe dans le formulaire.
2. Jeton API OVH (celui des domaines, onglet « API OVH ») avec en plus : `GET /email/domain/*` et `POST /email/domain/*`.
3. DNS : MX chez OVH et SPF `include:mx.ovh.com` (la page affiche un contrôle indicatif ; DKIM s'active dans l'espace client OVH).

## Réglages (`.secrets/mailer.json`, ou variables `MAILER_<CLÉ>`)

`domain` (bricbrok.com), `account` (contact), `from_name` (Bricbrok), `host` (ssl0.ovh.net), `port` (465), `secure` (ssl|tls),
`pass`, `enabled` (1|0). Supprimer le fichier ou décocher « Utiliser ce mailer » rend l'envoi à `mail()`.

## Tests

`vendor/bin/phpunit tests/PortalMailerTest.php` — double du client OVH et du transport SMTP ; aucun appel réseau.
Non testé automatiquement : l'envoi SMTP réel vers OVH et la création réelle de la boîte (à essayer en préprod).
