#!/usr/bin/env bash
# Contrôles rapides d'un site déployé (ou local) : requêtes HTTP ordinaires, rien n'est modifié.
#
# Usage : scripts/smoke-remote.sh <url> <local|preprod|prod>
#   ex. : scripts/smoke-remote.sh https://brocs.arrimage.com preprod
#
# Vérifie : site joignable, /health.php (200 + bon environnement), HTTPS, indexation
# (noindex hors prod), et que bases/secrets/dossiers internes ne sont pas téléchargeables.
# Code de sortie : 0 si aucun échec (les avertissements n'en font pas), 1 sinon.
set -u

URL="${1:-}"; EXPECT="${2:-}"
case "$EXPECT" in local|preprod|prod) ;; *) echo "Usage : $0 <url> <local|preprod|prod>" >&2; exit 2 ;; esac
case "$URL" in http://*|https://*) URL="${URL%/}" ;; *) echo "Usage : $0 <url> <local|preprod|prod>" >&2; exit 2 ;; esac
command -v curl >/dev/null 2>&1 || { echo "curl est requis." >&2; exit 2; }

PASS=0; FAIL=0; WARN=0
pass() { PASS=$((PASS+1)); echo "  ✓ $1"; }
fail() { FAIL=$((FAIL+1)); echo "  ✗ $1"; [ -n "${2:-}" ] && echo "      → $2"; }
warn() { WARN=$((WARN+1)); echo "  ⚠ $1"; [ -n "${2:-}" ] && echo "      → $2"; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
STATUS=""
fetch() { # fetch CHEMIN → STATUS, $TMP/h (en-têtes), $TMP/b (corps) ; ne suit jamais les redirections
  STATUS="$(curl -sS --connect-timeout 10 --max-time 30 -D "$TMP/h" -o "$TMP/b" -w '%{http_code}' "$URL$1" 2>"$TMP/e")" || STATUS="000"
}
header() { grep -i "^$1:" "$TMP/h" | tail -1 | sed 's/^[^:]*: *//' | tr -d '\r'; }

echo "Contrôle $EXPECT — $URL"

echo "Disponibilité"
fetch "/"
case "$STATUS" in
  000) fail "site injoignable" "$(tr '\n' ' ' < "$TMP/e")"; echo "Arrêt : le site ne répond pas."; exit 1 ;;
  200|301|302|303|307|308) pass "page d'accueil : $STATUS" ;;
  *) fail "page d'accueil : $STATUS" ;;
esac

fetch "/health.php"
if [ "$STATUS" = "200" ]; then pass "/health.php : 200"
else fail "/health.php : $STATUS" "sonde de supervision en erreur : $(head -c 200 "$TMP/b")"; fi
got="$(sed -n 's/.*"env"[[:space:]]*:[[:space:]]*"\([a-z]*\)".*/\1/p' "$TMP/b" | head -1)"
if [ "$got" = "$EXPECT" ]; then pass "environnement annoncé : $got"
elif [ -z "$got" ]; then fail "environnement non annoncé par /health.php" "includes/app-env.php est-il déployé ?"
else fail "environnement annoncé « $got », attendu « $EXPECT »" "mauvais dossier de déploiement, ou .app-env erroné sur le serveur"; fi

echo "HTTPS"
case "$URL" in
  https://*)
    pass "adresse en HTTPS"
    code="$(curl -sS --connect-timeout 10 --max-time 20 -o /dev/null -w '%{http_code} %{redirect_url}' "http://${URL#https://}/" 2>/dev/null || echo '000 ')"
    case "$code" in
      30[1278]\ https://*) pass "http:// redirige vers https://" ;;
      000*) warn "http:// ne répond pas" ;;
      *) warn "http:// ne redirige pas vers https://" "activer la redirection HTTPS chez OVH" ;;
    esac ;;
  *) [ "$EXPECT" = "local" ] && pass "HTTP (local)" || fail "adresse en HTTP" "préprod et prod doivent être en HTTPS" ;;
esac

echo "Indexation"
fetch "/health.php"
robots="$(header x-robots-tag)"
if [ "$EXPECT" = "prod" ]; then
  case "$robots" in *noindex*) fail "prod en noindex ($robots)" "la prod ne serait pas référencée : vérifier .app-env" ;; *) pass "prod indexable" ;; esac
  fetch "/robots.txt"
  if [ "$STATUS" = "200" ] && grep -qiE '^Disallow:[[:space:]]*/[[:space:]]*$' "$TMP/b"; then
    fail "robots.txt de la prod interdit tout" "robots.txt de préprod resté en place ? le supprimer ou le remplacer"
  fi
else
  case "$robots" in *noindex*) pass "X-Robots-Tag: noindex" ;; *) fail "pas de noindex hors production" "includes/app-env.php non pris en compte ?" ;; esac
fi

echo "Fichiers qui ne doivent pas être publics"
for p in /brocante.db /data/ /tenants/petit-chalet/tenant.php /.secrets/ /.env.deploy /.env.deploy.prod /.git/config /scripts/preflight.sh /includes/functions.php /schema.sql; do
  fetch "$p"
  if [ "$STATUS" = "200" ]; then
    if [ "$p" = "/brocante.db" ] && ! head -c 15 "$TMP/b" | grep -q "SQLite format"; then pass "$p : 200 mais ce n'est pas la base"
    else fail "$p téléchargeable (200)" "à bloquer d'urgence (.htaccess)"; fi
  else pass "$p non accessible ($STATUS)"; fi
done

echo
if [ "$FAIL" -eq 0 ]; then echo "✅ $PASS contrôle(s) réussi(s)$([ "$WARN" -gt 0 ] && echo ", $WARN avertissement(s)")"
else echo "❌ $FAIL échec(s), $PASS réussi(s)$([ "$WARN" -gt 0 ] && echo ", $WARN avertissement(s)")"; fi
[ "$FAIL" -eq 0 ]
