# Synchronisation multi-plateformes (option payante)

Préparez et exportez vos annonces vers les plateformes de seconde main depuis le catalogue de la boutique. L’extension Chrome locale préremplit les formulaires et, en mode automatique explicitement activé, peut publier depuis le navigateur du commerçant. **Aucune publication ne part du serveur de la boutique.**

## Outil indépendant

Cet outil n’est ni affilié, ni mandaté, ni sponsorisé par les plateformes tierces. Le commerçant reste seul responsable du respect de leurs conditions d’utilisation, de la sécurité de ses comptes et de la conformité de ses annonces. Aucune interopérabilité continue n’est garantie : un changement d’interface ou une restriction d’automatisation peut interrompre le module sans préavis. Les noms et marques cités appartiennent à leurs titulaires et sont mentionnés à titre descriptif.

## Option payante

1. L’exploitant active le plugin pour la boutique (**Portail → Plugins**).
2. Le commerçant ouvre **Annonces LBC / Vinted** dans l’admin :
   - essai gratuit 14 jours (une fois), ou
   - abonnement mensuel Stripe (prix dans `data/saas/addons.json`, défaut 9,90 €).
3. Sans abonnement / essai / accès offert (`subscription_status = complimentary`), l’API de l’extension répond **402**.

Accès offert (sans Stripe) : dans la base de la boutique,

```sql
INSERT INTO am_settings (key, value) VALUES ('subscription_status', 'complimentary')
ON CONFLICT(key) DO UPDATE SET value = excluded.value;
```

## Extension

Dossier `extension/` (téléchargeable en ZIP depuis l’admin). Chargement : `chrome://extensions` → mode développeur → « Charger l’extension non empaquetée ».

Sur Vinted (formulaire d'une seule page), l'extension remplit titre, description, catégorie (recherche dans l'arbre, première entrée « Femmes / Hommes / Enfants » selon la fiche ; à défaut la première suggestion, signalée), puis marque (celle de la fiche si elle existe à l'identique chez Vinted, jamais une marque voisine ; sinon « Inconnu », car Vinted refuse l'annonce sans marque dans la plupart des catégories), taille, état, couleurs (2 au plus), matériau, prix et format de colis recommandé. Le bouton de mise en ligne est « Ajouter » ; la simulation s'arrête avant.

Identifiant fixe : le manifeste contient une clé publique (`key`), donc l'extension garde le même identifiant (`idcomcikoeeadimpadaeihhaahaagkge`) quel que soit le dossier d'où elle est chargée. Ses réglages (adresse, jeton) restent en place au rechargement ; seule une suppression de l'extension les efface. Changer cette clé change l'identifiant et vide les réglages.

Réglages : URL de la boutique (par exemple `https://<domaine>/<boutique>/boutique.php`) + jeton `am_…` créé dans l’admin. L’extension accepte aussi directement l’URL API `https://<domaine>/<boutique>/api/annonces`.

Dans la fiche d’un article, **Télécharger les photos** permet de choisir le Bureau : l’extension y crée un dossier avec les photos, prêtes à être importées en lot.

Le bouton **Exporter les données JSON** télécharge un fichier contenant l’ensemble des champs de la fiche article et les URL des photos, pour préparer ou archiver l’annonce.

Sur le formulaire de dépôt, l’extension repère les champs par leur libellé visible, leur `aria-label`, leur `name` ou leurs attributs de test (`data-testid`, `data-test`, `data-qa`, `data-cy`) plutôt que par une position profonde dans le DOM, puis préremplit titre, description, prix, état, marque, couleur, matière et taille/dimensions lorsque ces champs sont présents. Les champs sont remplis un par un avec un court tempo, et non en une seule rafale. Sur Leboncoin, l'extension suit le déroulé du formulaire (portage du script Playwright d'origine) : titre saisi comme au clavier, clic sur la catégorie proposée (jamais sur la navigation du site ; sans suggestion reconnue, la pièce attend votre choix), photos jointes dès que le champ apparaît, puis les menus Univers, Marque (repli « Sans marque » / « Autre »), Genre (livres), Taille ou Pointure, Couleur et État, enfin description, prix (arrondi à l'euro) et écran « Boostez votre annonce » où seul « Déposer sans booster » est cliqué. Les libellés de menu viennent d'abord de la table de correspondances de la boutique, puis des valeurs de repli codées dans `content/leboncoin.js`. Sur Vinted, elle propose aussi la nature et la sous-catégorie du catalogue comme indication ; la catégorie finale doit être vérifiée et choisie dans les options Vinted si le champ ne peut pas être prérempli exactement.

Après avoir cliqué sur **Remplir Leboncoin** ou **Remplir Vinted**, un panneau latéral Chrome affiche les informations de l’article et reste ouvert pendant le chargement des photos. Il permet aussi de relancer le préremplissage. Cette fonction nécessite une version récente de Chrome et le droit « panneau latéral » de l’extension.

## Données sensibles et transport

- L’extension ne lit ni ne transmet les cookies de session Leboncoin ou Vinted, ni aucun mot de passe. Aucun identifiant de ces plateformes n’est stocké côté boutique : chaque action se déroule dans la session déjà ouverte du navigateur du commerçant.
- Le seul secret conservé dans `chrome.storage.local` est le jeton `am_…` de la boutique, utilisé dans l’en-tête `X-Annonces-Token` vers l’API du pack. Il est révocable depuis l’administration.
- L’adresse de la boutique doit être en HTTPS. Une adresse HTTP n’est acceptée que pour un hôte local ou un réseau privé de développement (`localhost`, `127.*`, `10.*`, `192.168.*`, `172.16–31.*`) ; sinon elle est refusée.
- Côté serveur, l’extension n’envoie que les données de l’annonce (référence, titre, prix, description, photos) et le résultat de publication ; rien de la session tierce.

## Mode automatique local (opt-in)

Le mode automatique publie **depuis le navigateur du commerçant**, jamais depuis le serveur de la boutique. Il est désarmé par défaut et doit être activé par une confirmation explicite dans l’extension. La simulation (remplissage et progression sans clic sur « Publier ») est cochée par défaut : elle déroule toute la file et classe chaque pièce « simulée » sans rien mettre en ligne.

Garde-fous : un article à la fois, plage horaire de travail (8 h–21 h) et plafond de 3 publications par heure en publication réelle, délais variables, pause de 6 h après toute vérification demandée par la plateforme, file d’attente enregistrée dans le navigateur (reprise après fermeture de la popup ou redémarrage), intervalle aléatoire (2 à 30 minutes, 5 par défaut), plafond quotidien (10 par défaut), arrêt immédiat en cas de captcha, vérification d’identité, téléphone ou message de sécurité, et pause après un article incomplet ou deux erreurs consécutives. L’extension enregistre chaque résultat dans l’historique (`/marquer`) et consulte `/publications` pour ne jamais republier une référence déjà en ligne.

Quand une pièce exige une action humaine (choix de catégorie dans l’arbre de la plateforme, photos refusées, vérification), la file se met en pause et **l’onglet reste ouvert** : complétez sur place, puis **Reprendre** retente la même pièce, ou **Passer** l’ignore. Une pièce publiée à la main hors mode automatique n’est pas détectée : décochez-la dans la sélection avant de lancer un passage.

**Avertissement.** Leboncoin et Vinted interdisent l’automatisation dans leurs conditions d’utilisation. Le mode automatique reste sous votre seule responsabilité et peut entraîner la suspension ou la fermeture de votre compte. L’éditeur n’est ni affilié, ni mandaté, ni sponsorisé par ces plateformes et ne garantit aucune interopérabilité continue.

## API agent (`/api/annonces/…`)

| Route | Auth | Rôle |
|---|---|---|
| `GET /ping` | optionnelle | santé / boutique |
| `GET /catalogue?q=` | jeton + option active | liste produits |
| `GET /produit/{ref}` | jeton + option active | fiche + photos |
| `GET /selection` | jeton + option active | sélection faite dans l’administration |
| `GET /publications?channel=` | jeton + option active | références déjà publiées (anti-doublon) |
| `POST /marquer` | jeton + option active | historique publication |

### Alertes (fiches et formulaires)

Avant le remplissage, `extension/quality.js` contrôle chaque fiche pour Leboncoin et pour Vinted : pastille ⚠ / ⛔ dans la liste de la popup, boîte « Contrôle avant remplissage » dans la fiche, confirmation avant un remplissage manuel bloquant, et **écartement** automatique des fiches bloquantes dans la file. Pendant le remplissage, les choix par défaut et les champs non remplis sont signalés ; le bouton « Contrôler le formulaire ouvert » et la détection de repères absents préviennent quand une plateforme change son formulaire (bannière persistante dans la popup). Un **état absent de la fiche** n'est pas bloquant : l'extension choisit « Bon état » (milieu de l'échelle) sur les deux plateformes et le signale. Ajouter ou ajuster une règle : `quality.js` et `tests/quality.test.js`.

### Cases à cocher dans l'extension

La liste d'articles de la popup propose une case à cocher par article, avec « Tout cocher » et « Tout décocher » ; le choix est mémorisé dans le navigateur. Au lancement de la file automatique, les articles cochés dans l'extension priment ; si rien n'est coché, c'est la sélection de l'administration de la boutique (ci-dessous) qui est utilisée.

### Sélection pour l'application locale

Dans l'administration du plug-in, ouvrez les modales **Sélectionner les articles pour l’application** et **Clé API et connexion Python**. L'application locale peut aussi ouvrir ces fenêtres dans le navigateur par défaut, en ouvrant l'URL d'administration et en ajoutant `&modal=selection` ou `&modal=token` (alias `&modal=api`). La connexion admin du navigateur reste nécessaire ; la clé n'est jamais délivrée par une route publique.

```http
GET https://<domaine>/<boutique>/api/annonces/selection
X-Annonces-Token: am_…
```

La réponse `items` contient pour chaque entrée `selected_at` et `product` (les champs normalisés de la fiche, dont les URL absolues des photos). La route est en lecture seule pour le jeton, limitée à 1 000 produits et nécessite l'option active. Exemple Python avec la bibliothèque standard :

```python
import json
from urllib.request import Request, urlopen

request = Request(
    "https://<domaine>/<boutique>/api/annonces/selection",
    headers={"X-Annonces-Token": "am_…", "Accept": "application/json"},
)
with urlopen(request, timeout=20) as response:
    selection = json.load(response)

for item in selection["items"]:
    product = item["product"]
    print(product["ref"], product["title"], len(product["photos"]))
```

La création ou la régénération du jeton reste réservée à un administrateur connecté. Appliquez les mises à jour du plug-in après déploiement.

En-tête : `Authorization: Bearer am_…` ou `X-Annonces-Token: am_…`.

## Départ de session (extension 1.7.0)

En mode automatique, l'extension ouvre l'accueil du site (leboncoin.fr / vinted.fr) puis clique elle-même sur
« Déposer une annonce » (Leboncoin) ou « Vends tes articles » (Vinted), comme le vendeur. Si le bouton est introuvable
après 12 s, elle rejoint le formulaire par son adresse. Une adresse de dépôt personnalisée dans les réglages est
respectée telle quelle (pas de passage par l'accueil).

## Pièces déjà en ligne (extension 1.8.0)

À chaque publication réelle, l'extension range une empreinte du contenu (titre, description, prix, état, taille, marque,
couleur, catégorie, photos) dans la note de la ligne d'historique (`[h:…]`). Au lancement d'une file :
- pièce déjà en ligne et **inchangée** → passée, sans rien ouvrir ;
- pièce déjà en ligne mais **modifiée** depuis → signalée « à mettre à jour » dans l'historique (la modification de
  l'annonce existante n'est pas encore automatique) ;
- pièce publiée avant cette version (sans empreinte) → l'empreinte actuelle est enregistrée, la pièce est passée.

## Menu « Univers » de Leboncoin (extension 1.8.2)

L'extension lit la liste réelle des univers proposés par le site et choisit l'entrée la plus proche de la pièce (valeur imposée par la table de correspondances, puis Femme / Homme pour la mode, puis comparaison des mots de la catégorie, du titre et de la description). Sans choix assez sûr, la pièce est **passée** (la file continue, ce n'est plus un arrêt) et le manque est inscrit dans « À renseigner » de la page Correspondances, avec la liste des univers du site. Une correspondance « Autre champ » peut maintenant porter une catégorie de la boutique (ex. Univers = « Maison » pour les paniers) sans toucher aux autres pièces.
