# Branding, logo et noms de domaine

À l’étape 2 des offres payantes (`/inscription/paiement.php`), le client peut
saisir son nom de boutique, choisir une activité principale et plusieurs
activités secondaires (ou préciser « Autre »), puis indiquer sa ville ou sa
zone. La ville bénéficie d’une autocomplétion et d’une découverte facultative
par géolocalisation, après autorisation explicite du navigateur. Les
coordonnées sont transmises au service d’adresse uniquement pour retrouver la
ville et ne sont pas enregistrées ; l’autocomplétion essaie la Base Adresse
Nationale, puis OpenStreetMap si aucun lieu n’est trouvé. OVH vérifie le domaine
correspondant en `.fr`, `.com`, `.net` et `.shop`
et, si nécessaire, des variantes proches. Si aucun nom ne convient, Gemini
peut proposer des noms tenant compte du métier et de l’emplacement ; chaque
suggestion peut être vérifiée à son tour. Le client peut choisir l’adresse
incluse sous le domaine de la plateforme ou présélectionner un domaine pour
l’acheter après la création. Une présélection n’est pas une réservation : le
domaine n’est commandé qu’après le devis OVH et le paiement dédié décrits plus
bas.

Les domaines déclarés disponibles sont accompagnés d’un devis OVH TTC pour la
première année, marge configurée incluse. Ce montant est indicatif et revérifié
avant l’achat ; il est séparé du total de l’abonnement affiché dans le
récapitulatif. Le client peut continuer sans arrêter son choix avec
« Je choisirai plus tard ». Ses suggestions et disponibilités sont conservées.
Une case d’accord, facultative et distincte du consentement relatif à la
création, autorise les relances par e-mail. Les relances sont manuelles depuis
`/portail/inscriptions.php`, possibles après sept jours, espacées d’au moins
sept jours et limitées à trois. Le lien dans chaque e-mail permet de retirer
cet accord.

Après la création d’une boutique, le client est redirigé vers
`/inscription/identite.php`. Cette étape facultative permet de choisir le
design de la boutique, propose aussi des idées de noms et un logo générés par
Gemini, puis un parcours de devis et de commande de domaine OVH payé via le
Stripe de la plateforme. La boutique est créée avant cette étape avec le modèle
par défaut ; le modèle choisi ensuite est enregistré dans les réglages de cette
boutique, sans affecter les autres commerces.

## Dépendances et secrets

Installer les dépendances avec `composer install` (le SDK `ovh/ovh` est déclaré
dans `composer.json`). Le déploiement du portail doit inclure `vendor/`.

La clé Gemini réutilise `GEMINI_API_KEY` ou `.secrets/gemini.key`. Le nommage
utilise `gemini-2.5-flash` et l’image `gemini-2.5-flash-image`.

Les identifiants OVH peuvent être fournis par environnement ou dans
`.secrets/ovh.json`, avec des permissions de lecture limitées au compte PHP :
sur le portail connecté, ouvre **API OVH** (`/portail/api.php?s=ovh`) pour les saisir
et les enregistrer dans ce fichier sans jamais les réafficher. Le fichier est
créé avec des droits privés (`0600`) dans le dossier `.secrets` (`0700`).
Le bouton **Tester l’API OVH** sur cette page contrôle, depuis le serveur,
l’authentification, la création d’un panier puis une recherche de disponibilité
de test ; il ne commande aucun domaine.

Pour les obtenir, connecte-toi au compte OVHcloud qui portera et paiera les
domaines, puis crée un jeton sur [la console API OVH](https://api.ovh.com/createToken/).
Choisis le endpoint correspondant au compte et accorde uniquement les droits
requis par le parcours : `POST /order/cart`, `POST /order/cart/*`,
`GET /order/cart/*` et `POST /domain/contact`. Pour un jeton plus restrictif,
ajoute aussi `GET /order/cart/*/item/*/requiredConfiguration`,
`POST /order/cart/*/item/*/configuration`, `POST /order/cart/*/validate` et
`POST /order/cart/*/checkout`. Recopie l’Application Key, l’Application Secret
et le Consumer Key dans le portail ; l’Application Secret peut ne plus être
visible après sa création.

```json
{
  "OVH_APPLICATION_KEY": "…",
  "OVH_APPLICATION_SECRET": "…",
  "OVH_CONSUMER_KEY": "…",
  "OVH_ENDPOINT": "ovh-eu",
  "OVH_SUBSIDIARY": "FR",
  "OVH_DOMAIN_MARGIN_EUR": "0"
}
```

Variables reconnues : `OVH_APPLICATION_KEY`, `OVH_APPLICATION_SECRET`,
`OVH_CONSUMER_KEY`, `OVH_ENDPOINT`, `OVH_SUBSIDIARY` et
`OVH_DOMAIN_MARGIN_EUR`. Le compte OVH doit autoriser les routes de panier,
catalogue, contacts de domaine et commandes, et disposer d’un moyen de paiement
par défaut : OVH ne crée la commande payée automatiquement que si ce moyen est
déjà configuré. Utiliser un compte de test OVH et Stripe en préproduction avant
d’activer l’option en production.

Le titulaire renseigné par le client est transmis à OVH comme propriétaire
légal. Les coordonnées et consentements sont conservés avec la demande
d’inscription, dans `data/inscriptions/` (répertoire non servi en HTTP).
L’API OVH reçoit les coordonnées selon son modèle `domain.Contact` : prénom,
nom, forme juridique, adresse structurée et téléphone au format international
(par exemple `+33612345678`). Le nom de l’organisation est requis si le titulaire
n’est pas une personne physique. La préparation du panier crée ce contact chez
OVH avant l’ouverture du paiement Stripe ; il peut donc rester enregistré chez
OVH même si le client n’achève pas son paiement.
Le compte OVH de la plateforme reste le compte gestionnaire et paie OVH ; le
client est titulaire légal au registre, mais le renouvellement annuel n’est pas
encore facturé automatiquement par cette intégration. Il faut organiser cette
facturation séparément ou transférer la gestion du domaine au client.

## Stripe et déroulé

Le paiement du domaine utilise la même clé et le même secret de webhook que les
formules de la plateforme. Aucun nouvel endpoint Stripe n’est nécessaire :
activer `checkout.session.completed` et, si les moyens de paiement différés
sont activés, `checkout.session.async_payment_succeeded` sur
`/inscription/webhook.php`.

Le serveur demande d’abord à OVH un devis TTC pour un enregistrement d’un an,
ajoute la marge configurée, puis crée une session Stripe ponctuelle. Le panier
OVH n’est validé qu’après vérification du paiement par Stripe (au retour client
ou par webhook). Si OVH ne confirme pas la commande, la demande passe en
`paid_needs_review` afin d’éviter un achat automatique en double ; elle doit
alors être vérifiée dans le compte OVH avant toute nouvelle action.

Les textes de consentement (titulaire, conditions OVH, renonciation au délai
de rétractation) et la politique de conservation des données doivent être
validés par l’exploitant avec son conseil juridique avant mise en production.
