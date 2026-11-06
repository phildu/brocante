#!/bin/bash
# Crée un nouveau commerce à partir de tenants/_modele.
#
# Usage : scripts/new-tenant.sh <slug> "<Nom du commerce>"
# Exemple : scripts/new-tenant.sh boulangerie-martin "Boulangerie Martin"
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
slug="${1:-}"
name="${2:-}"

if ! [[ "$slug" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || [ -z "$name" ]; then
  echo "Usage : scripts/new-tenant.sh <slug> \"<Nom du commerce>\"" >&2
  echo "Le slug ne contient que des minuscules, chiffres, - et _." >&2
  exit 1
fi
dir="$ROOT/tenants/$slug"
if [ -e "$dir" ]; then
  echo "Le commerce existe déjà : tenants/$slug" >&2
  exit 1
fi

cp -r "$ROOT/tenants/_modele" "$dir"
mkdir -p "$ROOT/assets/tenants/$slug"
# Logo provisoire : celui du Petit Chalet, à remplacer.
cp "$ROOT/assets/logo.png" "$ROOT/assets/tenants/$slug/logo.png"

php -r '
  [, $dir, $slug, $name] = $argv;
  $export = var_export($name, true);
  $tenant = file_get_contents("$dir/tenant.php");
  $tenant = preg_replace("#// Modèle de commerce.*?\n\n#s", "// Configuration du commerce : " . addcslashes($name, "\\\\") . ".\n\n", $tenant);
  $tenant = str_replace(["\x27Mon Commerce\x27", "mon-commerce"], [$export, $slug], $tenant);
  file_put_contents("$dir/tenant.php", $tenant);
  $seed = json_decode(file_get_contents("$dir/seed-data.json"), true);
  $seed["brand"]["name"] = $name;
  file_put_contents("$dir/seed-data.json", json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
' "$dir" "$slug" "$name"

echo "✅ Commerce créé : tenants/$slug/"
echo "   1. Adapter tenants/$slug/tenant.php (URL, mot de passe, couleurs, catégories, textes)"
echo "   2. Adapter tenants/$slug/seed-data.json (accueil, histoire, contact, premiers produits)"
echo "   3. Remplacer le logo : assets/tenants/$slug/logo.png"
echo "   4. Essayer : scripts/cloud-setup.sh $slug"
