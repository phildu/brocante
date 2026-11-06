# Découpage en 4 applications — Android d'abord, iOS ensuite

État du code au 5 novembre 2026. Ce document dit **quoi existe déjà**, **comment le découper**
et **dans quel ordre** livrer, pour que la version iOS soit une simple recompilation
de la version Android.

## 1. Les 4 applications et ce qui existe déjà

La plateforme a déjà un socle d'applications installables (`includes/apps.php` : `APP_KEYS`,
identifiants natifs stables `fr.brocs.<famille>.<enseigne>`, manifestes, service workers).
Les quatre applications visées s'y rattachent :

| # | Application | Public | Existe aujourd'hui (web) | Clé `APP_KEYS` | Identifiant natif |
|---|-------------|--------|--------------------------|----------------|-------------------|
| 1 | **Studio photo/vidéo** (shooting) | vendeur, photographe | `admin/shooting-assistant.php`, `studio.php`, `admin/shop-shooting.php`, `connecteur-shooting.php` ; envois par `admin/shooting-assistant-action.php` et `admin/quick-add-action.php` | `article` | `fr.brocs.article.<enseigne>` |
| 2 | **Client / visiteur** (fidélité, suivi d'achat, bourse d'échange) | clients, visiteurs | `visiteur.php` (plugin « Espace visiteur & fidélité »), `galerie/visiteur.php`, `bourse.php`, `suivi.php`, `compte.php` | `shop` + `gallery_visitor` | `fr.brocs.boutique.<enseigne>`, `fr.brocs.galerie.visiteur.<galerie>` |
| 3 | **Gestion de vide-grenier** (scan QR, exposants, présence) | organisateur | `galerie/admin.php` → portail en mode restreint (`portail/galerie-*.php`), scan et fidélité dans `includes/gallery-loyalty.php`, `includes/gallery-locate.php` | `gallery_admin` | `fr.brocs.galerie.admin.<galerie>` |
| 4 | **Caisse** (vente et buvette) | vendeur, buvette | `vente.php` → `caisse.php`, `includes/pos.php`, `admin/pos-action.php` ; buvette : module `buvette/` sur la branche `main` (voir `BUVETTE.md`) | `vente` | `fr.brocs.vente.<enseigne>` |

Les applications 2 et 3 sont « déployées à l'identique » d'une galerie à l'autre : la galerie est un
paramètre (`?g=<slug>`), pas un code différent. Le même principe vaut pour les boutiques
(`tenants/<slug>`).

**À régler avant tout : deux lignées de code.** La branche de production
(`checkpoint/etat-prod-2026-11-05`) contient le Studio, la caisse, les galeries et le portail ;
`main` contient la buvette et les invendus mais pas le reste. Il faut les réunir avant de
construire l'application Caisse, sinon la buvette ne sera pas dans le même code que la vente.

## 2. Principe d'architecture : un socle, quatre coques

Le serveur PHP reste le cerveau. Chaque application mobile est une **coque fine** qui charge
l'interface web existante et y ajoute les capacités natives qu'un navigateur n'offre pas bien.

```
                     ┌────────────── serveur PHP (inchangé) ──────────────┐
                     │ pages HTML + endpoints JSON (*-action.php, api.php) │
                     └──────────────▲──────────────▲──────────────────────┘
                                    │ HTTPS         │ jeton (étape 2)
       ┌─────────────┬──────────────┴──┬───────────┴──────┬───────────────┐
       │ Studio      │ Client/visiteur │ Vide-grenier     │ Caisse        │
       │ (article)   │                 │ (gallery_admin)  │ (vente)       │
       └─────┬───────┴────────┬────────┴─────────┬────────┴───────┬───────┘
             └──── socle JS partagé (caméra, voix, file d'envoi, hors ligne) ────┘
                       └──── coque Capacitor : Android maintenant, iOS ensuite ────┘
```

Choix recommandé : **Capacitor** (une base de code web → projets Android et iOS). Il évite de
réécrire quatre interfaces en Kotlin et en Swift, et reprend telles quelles les pages déjà testées.
Une application « simple lien » (TWA) suffirait pour Android seul, mais ne donnerait ni la
lecture NFC/Bluetooth ni les envois en arrière-plan, et ne se transpose pas sur iOS.

### Ce qui change côté serveur (préparation)

1. **Identifiants stables** : déjà en place (`app_native_id`, `app_manifest_id`). Ne pas les changer.
2. **Authentification par jeton** : aujourd'hui tout passe par un cookie de session
   (`is_admin`, `sess_<enseigne>`). Une coque native a besoin d'un jeton qui survit à la fermeture
   de l'application : ajouter un échange « identifiant + mot de passe → jeton longue durée »
   (réutiliser `includes/unified-auth.php`, qui sait déjà ouvrir un espace à partir d'un compte unique)
   et accepter `Authorization: Bearer` en plus du cookie dans les endpoints `*-action.php`.
3. **Contrat d'API écrit** : lister les actions JSON de chaque application (voir § 4) dans un
   fichier OpenAPI. Les endpoints existent déjà ; il manque leur description et leur version.
4. **Choix de l'enseigne à la connexion** : un seul binaire par application, l'enseigne (ou la
   galerie) est choisie au login. Une version « marque blanche » (un binaire par enseigne,
   `app_native_id` différent) reste possible plus tard sans refonte.

### Ce qui se partage (socle JS)

À extraire de `assets/shooting-assistant.js` (2 850 lignes, aujourd'hui un seul fichier) pour que
les quatre applications s'en servent :

| Module | Contenu actuel | Servira à |
|--------|----------------|-----------|
| `camera` | `ensureStream`, capture, caméra native `capture=environment` | Studio, Caisse (scan code-barres), Vide-grenier (scan QR) |
| `voice` | `speak`, `listenMode`, validation vocale | Studio, Caisse (saisie mains libres) |
| `upload` | `post`, `shrink`, envoi vidéo par morceaux | Studio |
| `queue` | *à créer* : file d'envoi hors ligne | Studio, Caisse |
| `session` | *à créer* : jeton, choix de l'enseigne | les quatre |

## 3. Capacités natives par application

| Capacité | Studio | Client | Vide-grenier | Caisse |
|----------|:------:|:------:|:------------:|:------:|
| Appareil photo natif (réglages standard) | ✔ | | | |
| Scan code-barres / QR | | ✔ (QR du lieu) | ✔ | ✔ |
| Voix : parler et écouter | ✔ | | | option |
| Notifications push | | ✔ (alertes, suivi) | ✔ | |
| Mode hors ligne + envoi différé | ✔ (lots) | | ✔ (jour J, réseau saturé) | ✔ (indispensable) |
| Paiement par carte (SDK du prestataire) | | | | ✔ |
| Imprimante / tiroir-caisse | | | | ✔ |
| Géolocalisation | | ✔ | ✔ | |

Point d'attention iOS : la reconnaissance vocale du navigateur (`SpeechRecognition`) n'existe
pas dans la vue web intégrée d'iOS. Prévoir dès maintenant un plugin natif (ou le clavier de dictée
d'iOS) pour la question « un, deux, trois » et la validation vocale du Studio. Sur Android, la
vue web intégrée la fournit.

## 4. Contrat d'API à documenter, application par application

| Application | Endpoints existants à décrire |
|-------------|-------------------------------|
| Studio | `admin/shooting-assistant-action.php` (`analyze`, `objects`, `enhance`, `validate`, `add_type`) ; `admin/quick-add-action.php` (`create`, `video`, `measure`, `objects`, `detoure`, `angles`, `recto`, `verso`, `ambiance`, `sheet`, `finish`, `jobs`, `get`, `discard`, `save`) |
| Client | `galerie/visiteur.php` (POST JSON : `login`, `me`, `scan`, `redeem`, `forget`) ; `cart-add.php`, `suivi.php`, `bourse.php` |
| Vide-grenier | `portail/action.php`, `portail/galerie-*.php`, `galerie/clic.php`, `galerie/exposant.php` |
| Caisse | `admin/pos-action.php` (`lookup`, `pickup_collect`, `pickup_release`, `sale_cash`, `sale_card`, `sale_status`, `sale_cancel`), `includes/pos.php` ; buvette : endpoints du module `buvette/` |

## 5. Organisation du dépôt

Ne rien déplacer côté PHP. Ajouter un dossier à part :

```
mobile/
  core/                 socle JS partagé (§ 2) — sources, compilées vers assets/
  apps/
    studio/             capacitor.config.json (appId fr.brocs.article)  → URL d'entrée /studio.php
    client/             capacitor.config.json (appId fr.brocs.boutique) → /visiteur.php ou /galerie/visiteur.php
    vide-grenier/       capacitor.config.json (appId fr.brocs.galerie.admin) → /galerie/admin.php
    caisse/             capacitor.config.json (appId fr.brocs.vente)    → /vente.php
```

Un projet Capacitor par application ; `android/` et `ios/` sont générés par application. Le même
dossier produit les deux plateformes : passer à iOS = ajouter la cible et les plugins équivalents.

## 6. Ordre de livraison

| Phase | Contenu | Résultat |
|-------|---------|----------|
| 0 | Réunir `main` (buvette, invendus) et la ligne de production ; figer les identifiants | un seul code pour les quatre applications |
| 1 | Jeton d'authentification + contrat d'API (§ 2) | une coque native peut se connecter sans cookie |
| 2 | Extraire le socle JS (caméra, voix, envoi) | le Studio n'est plus un bloc unique |
| 3 | **Studio sur Android** (Capacitor, caméra native, envois en arrière-plan) | première application publiée en test fermé |
| 4 | **Caisse sur Android** (scan, hors ligne, SDK de paiement) | encaissement le jour du vide-grenier |
| 5 | **Vide-grenier**, puis **Client** sur Android | les quatre applications Android |
| 6 | iOS : ajouter la cible, plugin vocal, tests TestFlight | même parcours que sur Android |

Ordre conseillé pour Android : Studio, puis Caisse. Le Studio est le plus abouti et sert de modèle
à la coque ; la Caisse est celle qui a le plus à gagner du natif (hors ligne, paiement).

## 7. Décisions à prendre

1. **Capacitor ou application 100 % native ?** Recommandation : Capacitor (§ 2).
2. **Un binaire pour toutes les enseignes, ou un par enseigne ?** Recommandation : un seul, choix à la
   connexion ; la marque blanche par enseigne vient plus tard si besoin.
3. **Paiement par carte de la Caisse** : aujourd'hui `sale_card` passe par Stripe Checkout (le client
   règle sur son téléphone). Garder cela, ou ajouter un lecteur de cartes (SDK Stripe Terminal ou autre) ?
   Le choix du lecteur détermine les plugins natifs à prévoir.
4. **Comptes de développeur** : Google Play (une fois) et Apple Developer (annuel), à ouvrir au nom
   de l'exploitant avant les phases 3 et 6.
5. **Règle des magasins d'applications** : Apple refuse les applications qui ne font qu'afficher un
   site. Chaque application doit utiliser au moins une capacité native réelle (§ 3) ; c'est le cas
   des quatre.
