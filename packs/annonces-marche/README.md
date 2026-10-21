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

Réglages : URL `https://<boutique>/api/annonces` + jeton `am_…` créé dans l’admin.

## API agent (`/api/annonces/…`)

| Route | Auth | Rôle |
|---|---|---|
| `GET /ping` | optionnelle | santé / boutique |
| `GET /catalogue?q=` | jeton + option active | liste produits |
| `GET /produit/{ref}` | jeton + option active | fiche + photos |
| `POST /marquer` | jeton + option active | historique publication |

En-tête : `Authorization: Bearer am_…` ou `X-Annonces-Token: am_…`.
