# Jeu de produits d'exemple pour tester les connecteurs

Même catalogue (thème brocante) en trois formats :

| Fichier | À importer dans |
|---|---|
| `woocommerce-produits.csv` | WooCommerce → Produits → **Importer** (importeur CSV natif) |
| `shopify-produits.csv` | Shopify → Produits → **Importer** |
| `produits-api.json` | l'API d'envoi du site (voir `CONNECTEURS.md`), ex. `curl … -d @exemples-test/produits-api.json` |

> Les photos sont des images `placehold.co` (une URL par photo) : elles servent à vérifier le téléchargement
> et l'ordre des photos. Remplacez-les par de vraies photos si vous voulez juger le rendu.
> Ces fichiers n'ont pas été importés dans une vraie boutique WooCommerce/Shopify (inaccessibles depuis mon
> environnement) ; seul le JSON a été vérifié avec la logique d'import du site. Si l'importeur d'une plateforme
> refuse une colonne, dites-le-moi : le format de ces CSV est à ajuster.

## Les 8 produits et ce que chacun vérifie

| Réf. | Produit | Cas testé | Résultat attendu dans le catalogue |
|---|---|---|---|
| VASE-001 | Vase en grès, 34 € (ancien prix 45 €), stock 3, 850 g, 2 photos | **Promo** + stock suivi + poids | Prix affiché `45 €` barré, promo `34 €`, badge « Promo », stock 3, poids 850 g, 2 photos, univers **Céramique** |
| TASSE-002 | Tasse en faïence, 12 €, stock 0 | **Épuisé** | Fiche créée mais en rupture (« Vendue »), absente de la boutique publique |
| LAMPE-003 | Lampe à pétrole, 1 250,50 € | **Prix à 4 chiffres** + HTML (`<b>`, `<br>`) | Prix `1 250,50 €`, description en texte propre, univers **Lumière** |
| CHAISE-004 | Chaise bistrot, 60 €, 4 kg | **Poids en kg** (WooCommerce) | 4000 g, univers **Bois & mobilier** |
| POT-005 | Pot de jardin, 18,90 €, stock non suivi | **Stock non géré** | Stock 1, prix `18,90 €`, univers **Jardin** |
| HORLOGE-006 | « Comtoise » 1890, 320 €, **sans photo** | Caractères spéciaux, guillemets, `<script>`, **aucune photo** | Nom intact, `alert('xss')` absent de la description, fiche sans photo (pas d'erreur) |
| BROUILLON-007 | « Brouillon — ne doit PAS être importé » | **Non publié** | **Absent** (WooCommerce/Shopify) — par l'API d'envoi, `active:false` crée la fiche en rupture |
| PLAID-008 | Plaid en laine, variantes **Rouge** (stock 2) et **Bleu** (stock 0) | **Variations** | 2 fiches : « Plaid en laine tissée — Rouge » (stock 2) et « — Bleu » (rupture), univers **Textile** |

Attendu au total (WooCommerce ou Shopify) : **8 fiches créées** (6 produits simples hors brouillon + 2 variantes), **1 ignoré**
(le brouillon), **0 échec**, toutes **masquées** (réglage par défaut).

## Scénario de test conseillé

1. **Première synchro** → vérifier le tableau ci-dessus (Catalogue → « Voir les produits »).
2. **Re-synchro sans rien changer** → `0 créé, 8 mis à jour`, aucun doublon, photos non dupliquées.
3. **Dans la boutique d'origine**, changer : le prix du vase (34 → 30 €), le stock de la chaise (1 → 0), supprimer la lampe.
   Re-synchroniser → vase à 30 €, chaise en rupture, lampe **en rupture (non supprimée)**, résumé « 1 retiré(s) de la vente ».
4. **Dans le catalogue du site**, renommer le vase et le démasquer, puis re-synchroniser → nom et publication **conservés**.
5. **Remettre la lampe** dans la boutique d'origine → elle repasse en vente.
6. **Retirer la promo** du vase (supprimer l'ancien prix) → badge « Promo » et prix promo disparaissent.
7. **Mauvaise clé** (modifier un caractère) → « Tester la connexion » affiche « Accès refusé », rien n'est retiré.
