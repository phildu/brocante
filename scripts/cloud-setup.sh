#!/bin/bash
# Prépare et lance la boutique d'un commerce en local — idéal dans une
# session cloud Claude Code : PHP + SQLite, aucun serveur à installer.
#
# Usage : scripts/cloud-setup.sh [slug] [port]
#         (slug par défaut : $TENANT, sinon petit-chalet ; port par défaut : 8000)
#
# Si la base du commerce n'existe pas, elle est créée (schema.sql) et remplie
# avec tenants/<slug>/seed-data.json. Une base existante n'est jamais écrasée.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export TENANT="${1:-${TENANT:-petit-chalet}}"
PORT="${2:-8000}"

if [ ! -f "tenants/$TENANT/tenant.php" ]; then
  echo "Commerce introuvable : tenants/$TENANT/tenant.php (créez-le avec scripts/new-tenant.sh)" >&2
  exit 1
fi

for ext in pdo_sqlite gd curl; do
  php -m | grep -qi "^$ext\$" || echo "⚠ Extension PHP manquante : $ext" >&2
done

db_path="$(php -r 'require "config.php"; echo DB_PATH;')"
if [ ! -s "$db_path" ]; then
  echo "▶ Création de la base $db_path"
  php seed.php
else
  echo "▶ Base existante conservée : $db_path"
fi

if [ -z "${ADMIN_PASSWORD:-}" ] && [ -z "$(php -r 'require "config.php"; echo ADMIN_PASSWORD;')" ]; then
  echo "ℹ Aucun mot de passe admin : définissez admin_password dans tenants/$TENANT/tenant.php"
  echo "  ou lancez avec ADMIN_PASSWORD=... pour accéder à /admin/."
fi

echo "▶ Boutique « $TENANT » sur http://127.0.0.1:$PORT (Ctrl+C pour arrêter)"
export SITE_URL="${SITE_URL:-http://127.0.0.1:$PORT}"
exec php -S "127.0.0.1:$PORT" -t "$ROOT" "$ROOT/router.php"
