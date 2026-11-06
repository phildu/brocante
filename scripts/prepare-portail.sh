#!/bin/bash
# Crée .env.deploy.portail (déploiement du portail, ex. brocs.arrimage.com)
# à partir des accès FTP de .env.deploy, et demande le compte du portail.
#
# Usage : scripts/prepare-portail.sh [dossier] [url]
#   par défaut : dossier « brocs », url https://brocs.arrimage.com
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FOLDER="${1:-brocs}"
URL="${2:-https://$FOLDER.arrimage.com}"
TARGET=".env.deploy.portail"

if [ ! -f .env.deploy ]; then
  echo "❌ .env.deploy introuvable : il faut les accès FTP du déploiement habituel." >&2
  exit 1
fi
if [ -f "$TARGET" ]; then
  read -r -p "$TARGET existe déjà. Le remplacer ? (o/N) " answer
  [[ "$answer" =~ ^[oOyY]$ ]] || { echo "Rien de changé."; exit 0; }
fi

# Accès FTP repris du déploiement habituel.
# shellcheck disable=SC1091
source .env.deploy
for var in FTP_SERVER FTP_USER FTP_PASS; do
  if [ -z "${!var:-}" ]; then
    echo "❌ $var manquant dans .env.deploy." >&2
    exit 1
  fi
done

read -r -p "Identifiant du portail [phil] : " PORTAIL_USER
PORTAIL_USER="${PORTAIL_USER:-phil}"
while true; do
  read -r -s -p "Mot de passe du portail (10 caractères minimum, invisible à la saisie) : " PORTAIL_PASSWORD; echo
  if [ ${#PORTAIL_PASSWORD} -lt 10 ]; then
    echo "Trop court, recommencez."
    continue
  fi
  read -r -s -p "Confirmez le mot de passe : " CONFIRM; echo
  [ "$PORTAIL_PASSWORD" = "$CONFIRM" ] && break
  echo "Les deux saisies diffèrent, recommencez."
done

# Valeurs entre apostrophes, pour que « source » les relise telles quelles.
quote() { printf "'%s'" "${1//\'/\'\\\'\'}"; }
umask 077
{
  echo "# Déploiement du portail — créé par scripts/prepare-portail.sh le $(date '+%d/%m/%Y')."
  echo "# Jamais envoyé sur GitHub (.gitignore)."
  echo "FTP_SERVER=$(quote "$FTP_SERVER")"
  echo "FTP_USER=$(quote "$FTP_USER")"
  echo "FTP_PASS=$(quote "$FTP_PASS")"
  echo "FTP_PATH_FRONT=$(quote "/www/$FOLDER/")"
  echo "URL_FRONT=$(quote "$URL")"
  echo "PORTAIL_USER=$(quote "$PORTAIL_USER")"
  echo "PORTAIL_PASSWORD=$(quote "$PORTAIL_PASSWORD")"
} > "$TARGET"

echo "✅ $TARGET créé : dossier OVH /$FOLDER/, $URL/portail/ (identifiant $PORTAIL_USER)."
echo "   Étape suivante : ./deploy-brocante.sh --portail --with-db"
