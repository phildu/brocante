# Centre d'aide public Brocs

Centre d'aide documentaire de la plateforme : FAQ par rôle, tutoriels pas à pas, glossaire, recherche sans accent et assistant local. Aucun service externe, aucune clé, aucun stockage de conversation.

## Pages

| Fichier | Rôle |
|---|---|
| `aide/index.php` | Page principale : recherche `?q=`, filtre `?role=`, FAQ, tutoriels, glossaire, assistant. |
| `aide/tutoriel.php` | Fiche tutoriel `?slug=…` : prérequis, étapes, erreurs, captures, storyboard vidéo. |
| `aide/_base.php` | Chargement du contenu, normalisation, recherche, chatbot serveur, JSON, helpers. |
| `aide/aide-index.json.php` | Index JSON public, `noindex`, utilisé par le panneau contextuel. |
| `aide/widget-demo.php` | Démonstration `noindex` du panneau et du code d'intégration. |
| `aide/assets/aide.css` | Styles limités au préfixe `.aide-`, y compris le panneau. |
| `aide/assets/aide.js` | Recherche instantanée et filtre de rôle, sans requête réseau. |
| `aide/assets/aide-chat.js` | Assistant documentaire local, sans requête ni stockage. |
| `aide/assets/aide-widget.js` | API `BrocsAide.open('slug')`, panneau latéral accessible. |
| `aide/contenu/*.json` | Base de contenu : FAQ, tutoriels, glossaire, captures, vidéos. |
| `aide/contenu/*.php` | Adaptateurs qui convertissent les JSON vers le schéma interne. |
| `aide/assets/captures/` | Fiches de capture ; aucune image réelle pour l'instant. |
| `aide/assets/videos/` | 5 sous-titres `.vtt` et storyboards ; aucun MP4. |

## Source de contenu

La base structurée est préparée dans `/Users/admin/Documents/projet/web local/brocs/contenu-aide/`. Elle est copiée dans `aide/contenu/` et reste la source de vérité éditoriale. Pour la mettre à jour :

```bash
cd "/Users/admin/Documents/projet/web local/brocante"
cp "../brocs/contenu-aide/faq.json" aide/contenu/
cp "../brocs/contenu-aide/tutoriels.json" aide/contenu/
cp "../brocs/contenu-aide/glossaire.json" aide/contenu/
cp "../brocs/contenu-aide/captures.json" aide/contenu/
cp "../brocs/contenu-aide/videos.json" aide/contenu/
cp "../brocs/contenu-aide/videos/"*.vtt aide/assets/videos/
```

Les 29 URL statiques citées par la base (FAQ et tutoriels) ont été contrôlées le 2026-11-03 : toutes existent, y compris `/aide/` depuis la livraison du centre d'aide. Les libellés d'interface cités entre guillemets ont aussi été revérifiés dans le code ; `Exposant lié` est dynamique (`gt_word($galerie, 'member')` + « lié »), donc correct pour un vide-grenier.

Les adaptateurs `faq.php`, `tutoriels.php` et `glossaire.php` appellent `aide_import_faq()`, `aide_import_tutoriels()` et `aide_import_glossaire()`. Aucune donnée n'est dupliquée dans le code PHP. Si un nom de capture change, régénérer les fiches `aide/assets/captures/fiche-<slug>.md`.

### Schéma interne

Chaque fiche normalisée porte : `slug`, `type` (`faq`, `tutoriel`, `glossaire`), `role` (`vendeur`, `organisateur`, `visiteur`, `commune`, `tous`), `titre`, `resume`, `mots_cles`, `etapes` (`titre`, `texte`, `ecran`, `lien`), `erreurs`, `prerequis`, `liens` (`libelle`, `url`), `url_cible`, `url_publique`, `captures`, `video`, `ecart`, `url`.

Le glossaire est indexé comme les autres fiches. Les termes sont définis dans `glossaire.json` ; aucune adresse de contact n'y figure.

## Recherche et assistant

- La recherche plie les accents, la casse et les pluriels simples, retire les mots vides français et note : titre 9, mots-clés 6, résumé 3, étapes 2, liens 2, erreurs/prérequis/écart 1, phrase exacte 8.
- Le filtre de rôle garde les fiches du rôle et celles marquées `tous`.
- Le formulaire fonctionne sans JavaScript avec `?q=` et `?role=`.
- Avec JavaScript, `aide.js` filtre instantanément depuis le corpus inline et met l'URL à jour via `history.replaceState`.
- L'assistant accepte `?question=` sans JavaScript. Le seuil est `AIDE_CHAT_MIN_SCORE = 10` et `AIDE_CHAT_MIN_COVERAGE = 0.5` ; en dessous, il refuse et propose `saas_config()['contact']` quand elle est configurée.
- Aucun appel réseau, aucun `localStorage`, aucun cookie de conversation. Le corpus est injecté dans la page avec `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP`.
- Point d'extension IA : `AIDE_IA_ENABLED` dans `_base.php`, à `false` par défaut. Le laisser à `false` tant qu'aucun service n'est validé ; le repli documentaire doit rester actif.

## Panneau d'aide contextuelle

Le panneau n'est activé nulle part. Pour l'utiliser dans `/portail/` ou `/admin/`, ajouter ces deux lignes dans le gabarit concerné, avant `</body>` :

```html
<link rel="stylesheet" href="/aide/assets/aide.css">
<script src="/aide/assets/aide-widget.js" defer></script>
```

Puis sur un bouton ou un lien existant :

```html
<button type="button" data-aide-open="organisateur-plan">Aide</button>
```

Ou en JavaScript : `BrocsAide.open('organisateur-plan')`. Le panneau charge `/aide/aide-index.json.php` une seule fois, se ferme avec Échap, le bouton de fermeture ou le fond, rend le focus au déclencheur et ne pose pas de piège de focus (`aria-modal="false"`). Aucun gabarit existant n'a été modifié pour l'activer.

## Accessibilité

Titres hiérarchisés, formulaire étiqueté, focus visible, contrastes AA, navigation clavier, `aria-live` sur les résultats et le journal de l'assistant, fermeture au clavier, cible de 360 px, respect de `prefers-reduced-motion` et feuille d'impression. Le chatbot interactif nécessite JavaScript ; la recherche et les fiches restent utilisables sans JavaScript.

## Captures et vidéos

- **Aucune capture réelle** n'a été produite : le serveur local n'a pas de compte de test documenté sans secret, et les écrans du portail exigent une connexion. Les 26 emplacements sont explicites dans les tutoriels, avec URL, compte/fixture, action, cadrage et texte de remplacement. Voir `assets/captures/README.md` et `fiche-<slug>.md`.
- **Aucun MP4** n'est fourni. Les 5 storyboards et les 5 `.vtt` sont prêts. Déposer `assets/videos/<slug>.mp4` active automatiquement le lecteur et les sous-titres. Voir `assets/videos/README.md`.

## Écarts constatés

| Sujet | Réalité vérifiée | Traitement dans l'aide |
|---|---|---|
| Export du plan | `portail/galerie-plan.php` n'a pas d'export image/PDF. | Le tutoriel `exporter-un-plan` documente le CSV des exposants, Excel/CSV/PDF du registre et l'impression du navigateur, et signale l'absence d'export direct du plan. |
| Studio | Le Studio est `/studio.php` sur téléphone. `packs/studio-video` est un module vidéo distinct. | Tutoriel `publier-une-piece-studio` sur le Studio photo ; le glossaire définit le Studio vidéo comme un module distinct. |
| Demandes d'emplacement | Ce sont les demandes d'exposants (`galerie-evenement.php`, `galerie-exposants.php`), pas la bourse. | Tutoriel `ouvrir-les-demandes` ; la bourse reste une FAQ distincte. |
| Google Places | La découverte automatique des commerces d'une rue n'est pas livrée. | La FAQ `commune-commerces` le dit explicitement et renvoie vers l'ajout manuel dans la galerie ou l'annuaire. |
| Bourse | Le message d'option manquante renvoie vers `/portail/galerie-types.php`, page inexistante. | La FAQ renvoie vers `/portail/offres-galerie.php` et signale le lien erroné. |
| Vidéo produit | Un MP4 exporté par `packs/studio-video` ne s'attache pas à une fiche produit ; la galerie produit n'accepte que des images. | La FAQ le dit et renvoie vers la bibliothèque de médias ou les réseaux. |
| Contact global | Il n'existe pas de formulaire de contact plateforme. | Le chatbot utilise `saas_config()['contact']` ; si elle est vide, aucun mailto n'est affiché. Le formulaire de contact d'une boutique dépend du module Messagerie. |
| Formulaire desktop | Le formulaire inline du catalogue poste vers `/admin/add-product.php` ; le Studio et `quick-add` passent par `/admin/quick-add-action.php`. | Le tutoriel décrit le parcours Studio et renvoie au catalogue, sans mélanger les deux endpoints. |
| Fixture de test | `rc-vide-grenier-test` n'a pas la réservation ouverte dans la configuration locale. | Les fiches de capture demandent d'ouvrir les demandes avant de photographier le bouton public. |
| Contact local | `saas_config()['contact']` est vide dans la configuration locale. | Le mailto est masqué localement ; il apparaît dès que la configuration de la plateforme renseigne une adresse. |

## Vérifications

```bash
cd "/Users/admin/Documents/projet/web local/brocante"
for f in aide/_base.php aide/index.php aide/tutoriel.php aide/widget-demo.php aide/aide-index.json.php aide/contenu/*.php; do php -l "$f"; done
php -S 127.0.0.1:8099
```

Dans un autre terminal :

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8099/aide/
curl -s "http://127.0.0.1:8099/aide/?q=plan" | grep -o '<h3><a[^>]*>[^<]*' | head
curl -s "http://127.0.0.1:8099/aide/?q=arrhes" | grep -o 'glossaire-arrhes' | head
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8099/aide/tutoriel.php?slug=creer-son-plan"
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8099/aide/aide-index.json.php
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8099/aide/widget-demo.php
curl -s "http://127.0.0.1:8099/aide/?question=comment%20cr%C3%A9er%20un%20plan%20%3F" | grep -o 'Créer le plan[^<]*' | head
curl -s "http://127.0.0.1:8099/aide/?question=quelle%20est%20la%20m%C3%A9t%C3%A9o%20%3F" | grep -o 'Je ne trouve pas[^<]*' | head
```

## Modifications partagées

- `includes/saas.php` : ajout de `'aide'` à `SAAS_RESERVED_SLUGS` et d'un lien `<a href="/aide/">Aide</a>` dans le pied de page de `saas_page_end()`. Aucune autre modification.
- Aucun déploiement, aucune modification de production, aucun commit git, aucune modification de Notion ni de `SUIVI_TACHES.md`.

## Statut

- FAQ : 35 questions. Tutoriels : 5. Glossaire : 20 termes.
- Recherche et assistant : fonctionnels sans dépendance.
- Captures : 0 réelle, 26 à faire. Vidéos : 0 MP4, 5 storyboards et sous-titres prêts.
- Intégration du panneau : documentée et démontrée, non activée dans les gabarits.
