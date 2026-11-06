#!/usr/bin/env bash
# Sauvegarde des bases SQLite d'un serveur (préprod ou prod) vers backups/<env>/<horodatage>/ (dossier non versionné).
# Lecture seule côté serveur : rien n'est écrit ni supprimé en ligne.
#
# Usage : scripts/backup-remote-db.sh <preprod|prod> [--portail | --tenant <slug>]
#   --portail       : data/*.db (tous les commerces) + les *.db de la racine (ex. brocante.db)
#   --tenant <slug> : la base de ce commerce (chemin lu dans sa config)
#   sans option     : comme --tenant petit-chalet
# Mêmes fichiers d'accès que deploy-brocante.sh (voir deploy/env.*.example).
#
# Limite : une base copiée par FTP pendant une écriture peut être incohérente. Le script lance
# PRAGMA integrity_check dessus (si sqlite3 est installé) ; en cas d'échec, relancer hors pic d'activité.
set -euo pipefail
cd "$(dirname "$0")/.."

ENVNAME="${1:-}"; shift || true
case "$ENVNAME" in preprod|prod) ;; *) echo "Usage : $0 <preprod|prod> [--portail | --tenant <slug>]" >&2; exit 2 ;; esac
PORTAIL=0; TENANT="${TENANT:-petit-chalet}"
while [ $# -gt 0 ]; do
  case "$1" in
    --portail) PORTAIL=1 ;;
    --tenant) TENANT="$2"; shift ;;
    *) echo "Option inconnue : $1" >&2; exit 2 ;;
  esac
  shift
done

if [ "$PORTAIL" = "1" ]; then
  f=".env.deploy.portail"; [ "$ENVNAME" = "prod" ] && f=".env.deploy.portail.prod"
else
  f=".env.deploy.$TENANT"; [ "$ENVNAME" = "prod" ] && f=".env.deploy.$TENANT.prod"
  if [ ! -f "$f" ] && [ "$TENANT" = "petit-chalet" ]; then
    [ "$ENVNAME" = "prod" ] && f=".env.deploy.prod" || f=".env.deploy"
  fi
fi
[ -f "$f" ] || { echo "❌ $f introuvable (modèle : deploy/env.$ENVNAME.example)" >&2; exit 1; }
command -v lftp >/dev/null 2>&1 || { echo "❌ lftp non installé (brew install lftp)" >&2; exit 1; }
# shellcheck disable=SC1090
source "$f"
: "${FTP_SERVER:?FTP_SERVER manquant dans $f}" "${FTP_USER:?FTP_USER manquant dans $f}" "${FTP_PASS:?FTP_PASS manquant dans $f}" "${FTP_PATH_FRONT:?FTP_PATH_FRONT manquant dans $f}"

dest="backups/$ENVNAME/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$dest"
chmod 700 backups "backups/$ENVNAME" "$dest" 2>/dev/null || true
echo "▶ Sauvegarde $ENVNAME (${URL_FRONT:-$FTP_SERVER}) → $dest"

opts='set ftp:ssl-allow yes
set ftp:ssl-force no
set net:timeout 30
set net:max-retries 3'
if [ "$PORTAIL" = "1" ]; then
  cmds="mirror --no-recursion -I '*.db' ${FTP_PATH_FRONT} $dest
mirror -I '*.db' ${FTP_PATH_FRONT}data $dest/data"
else
  export TENANT
  db_rel="$(php -r 'require "config.php"; echo substr(DB_PATH, strlen(__DIR__) + 1);')"
  mkdir -p "$dest/$(dirname "$db_rel")"
  cmds="get ${FTP_PATH_FRONT}${db_rel} -o $dest/$db_rel"
fi
lftp -c "$opts
open ftp://$FTP_USER:$FTP_PASS@$FTP_SERVER
$cmds
quit"

count=0; bad=0
while IFS= read -r db; do
  [ -n "$db" ] || continue
  count=$((count + 1))
  chmod 600 "$db"
  if command -v sqlite3 >/dev/null 2>&1; then
    res="$(sqlite3 "$db" 'PRAGMA integrity_check;' 2>&1 | head -1)"
    if [ "$res" = "ok" ]; then echo "  ✓ $db"; else echo "  ✗ $db : $res"; bad=$((bad + 1)); fi
  else
    echo "  • $db (sqlite3 absent : intégrité non vérifiée)"
  fi
done < <(find "$dest" -type f -name '*.db')

[ "$count" -gt 0 ] || { echo "❌ Aucune base récupérée : chemin ou identifiants à vérifier." >&2; exit 1; }
[ "$bad" -eq 0 ] || { echo "❌ $bad base(s) incohérente(s) : relancer hors pic d'activité." >&2; exit 1; }
echo "✅ $count base(s) sauvegardée(s) dans $dest"
