#!/usr/bin/env bash
# Déploiement par SSH/SFTP (rsync) des fichiers modifiés depuis un tag.
# Prérequis : offre OVH avec SSH actif (Business Pro/Performance ou VPS), clé
# publique autorisée, hôte SSH connu. Tant que SSH n'est pas actif, le
# déploiement reste manuel en FTP (scripts/preflight.sh reste utile).
#
# Usage :
#   DEPLOY_SSH=user@ssh.cluster0xx.hosting.ovh.net scripts/deploy-ssh.sh [--apply] [base]
#   DEPLOY_PATH=/www/brocs/ (chemin distant, barre finale comprise)
#   DEPLOY_URL=https://bricbrok.com/ (contrôles après envoi)
set -euo pipefail
cd "$(dirname "$0")/.."

apply=0
if [[ "${1:-}" == "--apply" ]]; then apply=1; shift; fi
base="${1:-$(git describe --tags --abbrev=0 2>/dev/null || echo HEAD~1)}"

: "${DEPLOY_SSH:?DEPLOY_SSH manquant (ex. user@ssh.cluster0xx.hosting.ovh.net)}"
remote_path="${DEPLOY_PATH:-/www/brocs/}"
remote_url="${DEPLOY_URL:-}"
[[ "$remote_path" == */ ]] || remote_path="$remote_path/"

scripts/preflight.sh >/dev/null || { echo "Pré-vol en échec : arrêt." >&2; exit 1; }

list=$(mktemp)
trap 'rm -f "$list"' EXIT
git diff --name-only "$base" -- . \
  | grep -Ev '^(data/|vendor/|tests/|uploads/import/|var/|db-backup/)|(^|/)\.env|(^|/)config\.local\.php$|(^|/)\.secrets/|\.db$|\.sqlite$' \
  | sort -u > "$list" || true
# Fichiers non suivis mais à envoyer (nouveaux modules).
git ls-files --others --exclude-standard \
  | grep -Ev '^(data/|vendor/|tests/|uploads/import/|var/|db-backup/)|(^|/)\.env|(^|/)config\.local\.php$|(^|/)\.secrets/|\.db$|\.sqlite$' \
  | sort -u >> "$list" || true
sort -u -o "$list" "$list"

count=$(wc -l < "$list" | tr -d ' ')
if [[ "$count" == "0" ]]; then echo "Aucun fichier à envoyer depuis $base."; exit 0; fi
echo "$count fichier(s) depuis $base :"; sed 's/^/  /' "$list"

flags=(-az --itemize-changes --files-from="$list" -e "ssh -o BatchMode=yes -o ConnectTimeout=8")
if [[ $apply -eq 0 ]]; then
  echo "Mode vérification (aucun envoi). Relancer avec --apply pour écrire."
  rsync "${flags[@]}" --dry-run ./ "$DEPLOY_SSH:$remote_path"
  exit 0
fi

rsync "${flags[@]}" ./ "$DEPLOY_SSH:$remote_path"

# Droits : dossiers 705, fichiers 604 (règle de l'hébergement OVH).
dirs=$(sed 's#/[^/]*$##' "$list" | grep -v '^\.$' | sort -u)
while IFS= read -r d; do
  [[ -z "$d" ]] && continue
  ssh -o BatchMode=yes "$DEPLOY_SSH" "mkdir -p '$remote_path$d' && chmod 705 '$remote_path$d'"
done <<< "$dirs"
ssh -o BatchMode=yes "$DEPLOY_SSH" "cd '$remote_path' && xargs chmod 604" < "$list"

if [[ -n "$remote_url" ]]; then
  for path in "" "health.php"; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${remote_url%/}/$path")
    printf '%-6s %s%s\n' "$code" "$remote_url" "$path"
  done
fi
