#!/usr/bin/env bash
# Workflow local → preprod → prod. Enchaîne les contrôles ; ne contourne aucun garde-fou de deploy-brocante.sh.
#
# Usage :
#   scripts/release.sh check                          pré-vol local (syntaxe PHP, tests, secrets) — n'envoie rien
#   scripts/release.sh status                         fichiers d'accès présents, derniers tags preprod-* / prod-*
#   scripts/release.sh preprod [options]              pré-vol → déploiement préprod → contrôle → tag preprod-<date>
#   scripts/release.sh prod [options]                 pré-vol → sauvegarde des bases → déploiement prod → contrôle → tag prod-<date>
#   scripts/release.sh preprod|prod --dry-run [...]   essai à blanc : liste ce qui partirait, n'écrit rien
# Options (transmises à deploy-brocante.sh) : --portail | --tenant <slug> | --dry-run | --first-install (prod) | --with-db (préprod)
#   --no-backup           prod seulement : saute la sauvegarde (tout premier déploiement, aucune base encore sur le serveur)
#   --confirm-prod        prod seulement : confirmation donnée par l'option (à n'utiliser qu'après validation humaine de la commande)
#   --skip-preprod-check  correctif urgent en prod sans passage préalable en préprod (à justifier dans DEPLOIEMENT.md)
#
# Les tags sont créés en local seulement ; les pousser à la main : git push origin <tag>.
set -euo pipefail
cd "$(dirname "$0")/.."

cmd="${1:-}"; [ $# -gt 0 ] && shift || true
say() { printf '\n\033[1m▶ %s\033[0m\n' "$1"; }
die() { printf '\033[0;31m❌ %s\033[0m\n' "$1" >&2; exit 1; }

env_file_for() { # reprend la résolution de deploy-brocante.sh
  local env="$1" portail="$2" tenant="$3" f
  if [ "$portail" = "1" ]; then f=".env.deploy.portail"; [ "$env" = "prod" ] && f=".env.deploy.portail.prod"
  else
    f=".env.deploy.$tenant"; [ "$env" = "prod" ] && f=".env.deploy.$tenant.prod"
    if [ ! -f "$f" ] && [ "$tenant" = "petit-chalet" ]; then [ "$env" = "prod" ] && f=".env.deploy.prod" || f=".env.deploy"; fi
  fi
  printf '%s' "$f"
}
url_of() { grep -E '^URL_FRONT=' "$1" 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"' " | sed 's#/*$##'; }

case "$cmd" in
  check)
    scripts/preflight.sh "$@"
    ;;

  status)
    echo "Branche : $(git rev-parse --abbrev-ref HEAD)   commit : $(git rev-parse --short HEAD)"
    for e in preprod prod; do
      echo; echo "[$e]"
      for p in "portail" "petit-chalet"; do
        if [ "$p" = "portail" ]; then f="$(env_file_for "$e" 1 x)"; else f="$(env_file_for "$e" 0 "$p")"; fi
        if [ -f "$f" ]; then echo "  accès : $f → $(url_of "$f")"; else echo "  accès : $f (absent)"; fi
      done
      last="$(git tag --list "$e-*" --sort=-creatordate | head -1)"
      if [ -n "$last" ]; then echo "  dernier tag : $last ($(git rev-parse --short "$last^{commit}"))"; else echo "  dernier tag : aucun"; fi
    done
    ;;

  preprod|prod)
    env="$cmd"
    portail=0; tenant="petit-chalet"; dry=0; first=0; nobackup=0; passthru=()
    while [ $# -gt 0 ]; do
      case "$1" in
        --portail) portail=1; passthru+=("$1") ;;
        --tenant) tenant="${2:-}"; passthru+=("$1" "$tenant"); shift ;;
        --dry-run) dry=1; passthru+=("$1") ;;
        --first-install) first=1; passthru+=("$1") ;;
        --no-backup) nobackup=1 ;;
        *) passthru+=("$1") ;;
      esac
      shift
    done
    [ "$nobackup" = "0" ] || [ "$env" = "prod" ] || die "--no-backup ne concerne que la prod."
    [ "$portail" = "1" ] || [ "$tenant" != "petit-chalet" ] || die "Précisez la cible : --portail (cas courant) ou --tenant <slug>."

    branch="$(git rev-parse --abbrev-ref HEAD)"
    if [ "$env" = "prod" ] && [ "$branch" != "main" ] && [ "$dry" = "0" ]; then
      die "La prod se déploie depuis la branche main (branche actuelle : $branch)."
    fi
    [ "$env" = "prod" ] || [ "$branch" = "main" ] || printf '\033[1;33m⚠ préprod depuis %s (et non main)\033[0m\n' "$branch"

    f="$(env_file_for "$env" "$portail" "$tenant")"
    [ -f "$f" ] || die "$f introuvable — modèle : deploy/env.$env.example"
    url="$(url_of "$f")"; [ -n "$url" ] || die "URL_FRONT manquant dans $f"

    say "1/4 Pré-vol (syntaxe PHP, tests, secrets)"
    scripts/preflight.sh

    if [ "$env" = "prod" ] && [ "$dry" = "0" ]; then
      say "2/4 Sauvegarde des bases de la prod"
      if [ "$first" = "1" ]; then
        echo "  --first-install : pas de base existante à sauvegarder."
      elif [ "$nobackup" = "1" ]; then
        printf '\033[1;33m⚠ --no-backup : aucune sauvegarde (à réserver au tout premier déploiement).\033[0m\n'
      else
        if [ "$portail" = "1" ]; then scripts/backup-remote-db.sh prod --portail; else scripts/backup-remote-db.sh prod --tenant "$tenant"; fi
      fi
    else
      say "2/4 Sauvegarde : ignorée ($([ "$dry" = "1" ] && echo essai à blanc || echo préprod))"
    fi

    say "3/4 Déploiement $env → $url"
    ./deploy-brocante.sh --env "$env" ${passthru[@]+"${passthru[@]}"}
    if [ "$dry" = "1" ]; then echo; echo "Essai à blanc terminé : pas de contrôle ni de tag."; exit 0; fi

    say "4/4 Contrôle du site déployé"
    scripts/smoke-remote.sh "$url" "$env" || die "Contrôle en échec sur $url : ne pas considérer cette version comme validée (voir DEPLOIEMENT.md, « Retour arrière »)."

    tag="$env-$(date +%Y-%m-%d-%H%M)"
    git tag -a "$tag" -m "Déploiement $env de $(git rev-parse --short HEAD) vers $url" HEAD
    echo; echo "✅ $env à jour : $url"
    echo "   Tag local $tag sur $(git rev-parse --short HEAD) — le pousser : git push origin $tag"
    [ "$env" = "preprod" ] && echo "   Valider à la main sur la préprod, puis : scripts/release.sh prod --portail"
    ;;

  *)
    sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
    [ -z "$cmd" ] || exit 2
    ;;
esac
