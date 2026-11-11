#!/usr/bin/env bash
# Contrôles avant tout déploiement : lint des fichiers modifiés, suite de tests,
# arbre de travail propre, aucun secret suivi par git.
# Usage : scripts/preflight.sh [--allow-dirty] [base]
#   base : référence de comparaison (défaut : dernier tag, sinon HEAD~1)
set -euo pipefail
cd "$(dirname "$0")/.."

dirty_ok=0
if [[ "${1:-}" == "--allow-dirty" ]]; then dirty_ok=1; shift; fi
base="${1:-$(git describe --tags --abbrev=0 2>/dev/null || echo HEAD~1)}"

fail=0
say() { printf '%-6s %s\n' "$1" "$2"; }

if [[ -n "$(git status --porcelain)" ]]; then
  if [[ $dirty_ok -eq 1 ]]; then
    say "WARN" "arbre de travail modifié (dérogation accordée)"
  else
    say "KO" "arbre de travail modifié : committer d'abord, ou --allow-dirty"
    fail=1
  fi
else
  say "OK" "arbre de travail propre"
fi

files=$( { git diff --name-only "$base" 2>/dev/null || true
           git diff --name-only
           git ls-files --others --exclude-standard
         } | sort -u )
php_files=$(printf '%s\n' "$files" | grep -E '\.php$' || true)

if [[ -z "$php_files" ]]; then
  say "OK" "aucun fichier PHP modifié depuis $base"
else
  bad=0
  while IFS= read -r f; do
    [[ -n "$f" && -f "$f" ]] || continue
    php -l "$f" >/dev/null 2>&1 || { say "KO" "syntaxe : $f"; bad=1; }
  done <<< "$php_files"
  if [[ $bad -eq 0 ]]; then
    say "OK" "$(printf '%s\n' "$php_files" | wc -l | tr -d ' ') fichier(s) PHP valides"
  else
    fail=1
  fi
fi

if [[ -x vendor/bin/phpunit ]]; then
  if vendor/bin/phpunit --no-coverage --colors=never >/tmp/preflight-phpunit.log 2>&1; then
    say "OK" "tests : $(grep -E '^(OK|FAILURES|Tests:)' /tmp/preflight-phpunit.log | tail -1)"
  else
    say "KO" "tests en échec (voir /tmp/preflight-phpunit.log)"
    fail=1
  fi
else
  say "SKIP" "vendor/bin/phpunit absent"
fi

if [[ -f vendor/tecnickcom/tc-lib-barcode/src/Barcode.php && -f vendor/tecnickcom/tc-lib-color/src/Model.php ]]; then
  say "OK" "bibliothèques Data Matrix présentes"
else
  say "KO" "bibliothèques Data Matrix absentes : installer les dépendances Composer avant déploiement"
  fail=1
fi

if git ls-files | grep -qE '(^|/)\.env|\.(key|pem|p12)$|(^|/)config\.local\.php$'; then
  say "KO" "un secret semble suivi par git"
  fail=1
else
  say "OK" "aucun secret suivi par git"
fi

if [[ $fail -ne 0 ]]; then
  echo "Pré-vol : échec, ne pas déployer." >&2
  exit 1
fi
echo "Pré-vol : prêt."
