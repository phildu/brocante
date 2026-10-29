# Annonces places de marché (option payante)

Assistant de dépôt **Leboncoin** / **Vinted** branché sur le catalogue de la boutique. L’extension Chrome préremplit le formulaire ; le commerçant reste connecté sur le site et **valide la publication lui-même** (pas de robot de publication).

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

Réglages : URL de la boutique (par exemple `https://<domaine>/<boutique>/boutique.php`) + jeton `am_…` créé dans l’admin. L’extension accepte aussi directement l’URL API `https://<domaine>/<boutique>/api/annonces`.

Dans la fiche d’un article, **Télécharger les photos** permet de choisir le Bureau : l’extension y crée un dossier avec les photos, prêtes à être importées en lot.

Le bouton **Exporter les données JSON** télécharge un fichier contenant l’ensemble des champs de la fiche article et les URL des photos, pour préparer ou archiver l’annonce.

Sur le formulaire de dépôt, l’extension repère les champs par leur libellé et préremplit titre, description, prix, état, marque, couleur, matière et taille/dimensions lorsque ces champs sont présents. Sur Vinted, elle propose aussi la nature et la sous-catégorie du catalogue comme indication ; la catégorie finale doit être vérifiée et choisie dans les options Vinted si le champ ne peut pas être prérempli exactement.

Après avoir cliqué sur **Remplir Leboncoin** ou **Remplir Vinted**, un panneau latéral Chrome affiche les informations de l’article et reste ouvert pendant le chargement des photos. Il permet aussi de relancer le préremplissage. Cette fonction nécessite une version récente de Chrome et le droit « panneau latéral » de l’extension.

## API agent (`/api/annonces/…`)

| Route | Auth | Rôle |
|---|---|---|
| `GET /ping` | optionnelle | santé / boutique |
| `GET /catalogue?q=` | jeton + option active | liste produits |
| `GET /produit/{ref}` | jeton + option active | fiche + photos |
| `GET /selection` | jeton + option active | sélection faite dans l’administration |
| `POST /marquer` | jeton + option active | historique publication |

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
