#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# verify-deploy.sh — vérifications après un déploiement (module Comptage de foule)
#
# Usage :
#   ./scripts/verify-deploy.sh                    → adresse lue dans .env.deploy (URL_FRONT)
#   ./scripts/verify-deploy.sh https://exemple.fr → adresse donnée
#   ./scripts/verify-deploy.sh --full             → télécharge aussi les ≈ 17 Mo du détecteur
#                                                   et compare leurs empreintes SHA-256
#   COMPTAGE_CODE=xxxx ./scripts/verify-deploy.sh → ajoute un essai de connexion bénévole
#                                                   (connexion + appel de l'API, sans rien écrire)
#
# Ne demande aucun identifiant FTP et ne modifie rien sur le serveur : ce sont des
# requêtes HTTP ordinaires, comme le ferait un navigateur. Code de sortie : 0 si
# aucun échec (les avertissements ⚠ n'en font pas), 1 sinon.
# Prérequis : bash et curl (présents sur macOS et Linux).
# ─────────────────────────────────────────────────────────────────────────────

set -u

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
[ -t 1 ] || { RED=''; GREEN=''; YELLOW=''; BLUE=''; BOLD=''; NC=''; }

URL=""; FULL=0
for arg in "$@"; do
    case "$arg" in
        --full) FULL=1 ;;
        -h|--help) sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        http://*|https://*) URL="${arg%/}" ;;
        *) echo "Argument inconnu : $arg (voir --help)"; exit 2 ;;
    esac
done
if [ -z "$URL" ] && [ -f .env.deploy ]; then
    # Lecture simple de URL_FRONT : on n'exécute pas le fichier (il contient des mots de passe).
    URL=$(grep -E '^URL_FRONT=' .env.deploy | head -1 | cut -d= -f2- | tr -d "\"' " | sed 's#/*$##')
fi
[ -z "$URL" ] && URL="https://brocante.arrimage.com"

command -v curl >/dev/null 2>&1 || { echo "curl est requis."; exit 2; }
if command -v sha256sum >/dev/null 2>&1; then SHA="sha256sum"; elif command -v shasum >/dev/null 2>&1; then SHA="shasum -a 256"; else SHA=""; fi

TMP=$(mktemp -d 2>/dev/null || mktemp -d -t verify)
trap 'rm -rf "$TMP"' EXIT
PASS=0; FAIL=0; WARN=0

pass() { PASS=$((PASS+1)); echo -e "  ${GREEN}✓${NC} $1"; }
fail() { FAIL=$((FAIL+1)); echo -e "  ${RED}✗${NC} $1"; [ -n "${2:-}" ] && echo -e "      ${RED}→${NC} $2"; }
warn() { WARN=$((WARN+1)); echo -e "  ${YELLOW}⚠${NC} $1"; [ -n "${2:-}" ] && echo -e "      ${YELLOW}→${NC} $2"; }
section() { echo -e "\n${BOLD}$1${NC}"; }

STATUS=""; CTYPE=""; HDR="$TMP/h"; BODY="$TMP/b"; JAR="$TMP/jar"

# fetch METHOD CHEMIN [options curl…] → STATUS, CTYPE, $HDR, $BODY (jamais de redirection suivie)
fetch() {
    local method="$1" path="$2"; shift 2
    : > "$HDR"; : > "$BODY"
    STATUS=$(curl -sS --connect-timeout 10 --max-time 60 -X "$method" -D "$HDR" -o "$BODY" -w '%{http_code}' "$@" "$URL$path" 2>"$TMP/err") || STATUS="000"
    CTYPE=$(grep -i '^content-type:' "$HDR" | tail -1 | sed 's/^[^:]*: *//' | tr -d '\r' | tr 'A-Z' 'a-z')
}
header() { grep -i "^$1:" "$HDR" | tail -1 | sed 's/^[^:]*: *//' | tr -d '\r'; }

# ── Joignabilité ──────────────────────────────────────────────────────────────
echo -e "${BOLD}Vérification du déploiement — $URL${NC}"
section "Site"
fetch GET "/comptage/login.php"
if [ "$STATUS" = "000" ]; then
    fail "site injoignable" "$(tr '\n' ' ' < "$TMP/err")"
    echo -e "\n${RED}${BOLD}Impossible de continuer : le site ne répond pas.${NC}"; exit 1
fi
case "$URL" in
    https://*) pass "adresse en HTTPS (requis pour la caméra, le mode hors ligne et l'écran allumé)" ;;
    *) warn "adresse en HTTP" "la caméra, le service worker (hors ligne) et l'écran allumé exigent HTTPS ; ils ne marcheront pas sur un téléphone" ;;
esac
case "$URL" in https://*)
    plain="http://${URL#https://}"
    code=$(curl -sS --connect-timeout 10 --max-time 20 -o /dev/null -w '%{http_code} %{redirect_url}' "$plain/comptage/login.php" 2>/dev/null || echo "000 ")
    case "$code" in
        30[1278]\ https://*) pass "http:// redirige vers https://" ;;
        000*) warn "http:// ne répond pas (rien à vérifier)" ;;
        *) warn "http:// ne redirige pas vers https://" "un bénévole qui tape l'adresse sans https n'aura ni caméra ni hors ligne : activer la redirection HTTPS chez OVH" ;;
    esac ;;
esac

# ── Pages du comptage ─────────────────────────────────────────────────────────
section "Comptage — pages et accès"
if [ "$STATUS" = "200" ] && grep -q "Comptage de foule" "$BODY"; then
    pass "page de connexion bénévole (200) — PHP et base SQLite fonctionnent, tables créées"
elif [ "$STATUS" = "500" ]; then
    fail "page de connexion : erreur 500" "base non inscriptible par PHP ? vérifier les droits de brocante.db et du dossier (chmod 664 / 775)"
else
    fail "page de connexion bénévole : réponse $STATUS (200 attendu)" "le dossier comptage/ est-il arrivé sur le serveur ?"
fi

fetch GET "/comptage/index.php"
loc=$(header location)
if [ "$STATUS" = "302" ] && echo "$loc" | grep -q "login.php"; then pass "compteur sans session → redirigé vers la connexion"
else fail "compteur sans session : réponse $STATUS (302 vers la connexion attendu)" "accès non protégé ?"; fi

fetch POST "/comptage/api.php" -H 'Content-Type: application/json' -d '{"event":1,"ops":[]}'
if [ "$STATUS" = "401" ]; then pass "API sans session → 401"; else fail "API sans session : réponse $STATUS (401 attendu)" "$(head -c 120 "$BODY")"; fi
fetch GET "/comptage/api.php"
if [ "$STATUS" = "405" ] || [ "$STATUS" = "401" ]; then pass "API en GET refusée ($STATUS)"; else fail "API en GET : réponse $STATUS (405 attendu)"; fi

fetch GET "/admin/comptage/index.php"
loc=$(header location)
if [ "$STATUS" = "302" ] && echo "$loc" | grep -q "login"; then pass "administration du comptage protégée (→ connexion admin)"
else fail "administration du comptage sans session : réponse $STATUS (302 attendu)" "page d'administration accessible sans mot de passe !"; fi

# ── Application installable / hors ligne ─────────────────────────────────────
section "Comptage — hors ligne et installation"
fetch GET "/comptage/sw.js"
if [ "$STATUS" = "200" ] && echo "$CTYPE" | grep -q "javascript" && grep -q "comptage-v" "$BODY"; then
    pass "service worker servi comme JavaScript ($(grep -o "comptage-v[0-9]*" "$BODY" | head -1))"
else fail "service worker : réponse $STATUS, type « $CTYPE »" "il doit être servi en JavaScript depuis /comptage/sw.js"; fi
swcache=$(header cache-control)
case "$swcache" in *max-age=[1-9][0-9][0-9][0-9][0-9][0-9]*) warn "sw.js mis en cache longtemps ($swcache)" "une mise à jour du compteur pourrait mettre du temps à arriver sur les téléphones" ;; esac

fetch GET "/comptage/manifest.json"
if [ "$STATUS" = "200" ] && grep -q '"start_url"' "$BODY"; then
    if command -v python3 >/dev/null 2>&1 && ! python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$BODY" 2>/dev/null; then fail "manifest.json : JSON invalide"
    else pass "manifest d'installation (200)"; fi
else fail "manifest.json : réponse $STATUS"; fi

for f in /assets/comptage.js /assets/comptage-vision.js /assets/comptage.css /assets/logo-macaron.png; do
    fetch GET "$f"
    if [ "$STATUS" = "200" ]; then pass "$f"; else fail "$f : réponse $STATUS"; fi
done

# ── Détecteur de personnes (mode caméra) ─────────────────────────────────────
section "Mode caméra — fichiers du détecteur (≈ 17 Mo)"
# chemin | taille attendue | SHA-256 attendu
VENDOR="assets/vendor/mediapipe/vision_bundle.js|137689|c8341c13009c2e75fa273b96f21002f31f7196830f6857f6f3b57fbfedc3ccdc
assets/vendor/mediapipe/wasm/vision_wasm_internal.js|204284|4a97e2520ba506c680ecd6ba6acfb146888afa0e2746d57f205352bc6ebb82eb
assets/vendor/mediapipe/wasm/vision_wasm_internal.wasm|9574032|f00ec4731faa23b3e714d00e88d4d10e2df5c0a427d3a2b4ae6e3526fdd14ef7
assets/vendor/mediapipe/efficientdet_lite0.tflite|7254339|4b59100025bea1235a84c1038879a6cccc9f6c49f5e41144e91e74d99e780993"

while IFS='|' read -r path size sum; do
    [ -z "$path" ] && continue
    name="${path#assets/vendor/mediapipe/}"
    fetch HEAD "/$path" -I
    if [ "$STATUS" != "200" ]; then
        fail "$name : réponse $STATUS" "fichier absent du serveur : relancer le déploiement (le dossier assets/vendor/ est-il bien envoyé ?)"
        continue
    fi
    # Les modules JavaScript exigent un type MIME JavaScript ; le .wasm est lu même sans type correct, mais plus lentement.
    case "$path" in
        *.js) echo "$CTYPE" | grep -q "javascript" && pass "$name — type JavaScript" || fail "$name : type « $CTYPE »" "le navigateur refusera de charger le module (voir assets/vendor/mediapipe/.htaccess)" ;;
        *.wasm) echo "$CTYPE" | grep -q "application/wasm" && pass "$name — type application/wasm" || warn "$name : type « $CTYPE »" "fonctionne, mais le chargement est plus lent ; vérifier que le .htaccess du dossier est pris en compte" ;;
        *) pass "$name — présent" ;;
    esac
    if [ "$FULL" = "1" ]; then
        # Sans Accept-Encoding : on compare les octets du fichier, pas une version compressée.
        curl -sS --connect-timeout 10 --max-time 300 -o "$TMP/dl" "$URL/$path" 2>/dev/null
        got=$(wc -c < "$TMP/dl" | tr -d ' ')
        if [ "$got" != "$size" ]; then fail "$name : $got octets reçus, $size attendus" "envoi FTP interrompu ou corrompu : relancer le déploiement"
        elif [ -n "$SHA" ]; then
            h=$($SHA "$TMP/dl" | cut -d' ' -f1)
            [ "$h" = "$sum" ] && pass "$name — taille et empreinte SHA-256 identiques" || fail "$name : empreinte différente" "fichier altéré pendant l'envoi (mode FTP ASCII ?) : relancer le déploiement"
        else pass "$name — taille identique ($size octets)"; fi
    else
        len=$(header content-length)
        if [ -n "$len" ]; then
            [ "$len" = "$size" ] && pass "$name — taille annoncée identique ($size octets)" || fail "$name : $len octets annoncés, $size attendus" "envoi incomplet ou corrompu : relancer le déploiement (ou --full pour le détail)"
        else warn "$name : taille non annoncée par le serveur" "relancer avec --full pour comparer les octets"; fi
    fi
done <<EOF
$VENDOR
EOF
[ "$FULL" = "1" ] || echo -e "  ${BLUE}ℹ${NC} contrôle de taille seulement ; ${BOLD}--full${NC} télécharge les fichiers et compare leurs empreintes SHA-256"

# ── Fichiers qui ne doivent jamais être publics ──────────────────────────────
section "Sécurité — fichiers qui ne doivent pas être téléchargeables"
check_hidden() {
    local path="$1" label="$2"
    fetch GET "$path"
    if [ "$STATUS" = "200" ]; then
        if [ "$path" = "/brocante.db" ] && ! head -c 15 "$BODY" | grep -q "SQLite format"; then
            pass "$label — réponse 200 mais ce n'est pas la base (page d'erreur)"
        else
            fail "$label téléchargeable ($path → 200)" "à bloquer d'urgence (.htaccess : Require all denied) ; si c'est la base, changer les codes d'accès"
        fi
    else pass "$label non accessible ($STATUS)"; fi
}
check_hidden "/brocante.db" "base de données"
check_hidden "/.env.deploy" "identifiants de déploiement"
check_hidden "/.secrets/admin_password.txt" "dossier .secrets"
fetch GET "/scripts/test-comptage.php"
if [ "$STATUS" = "200" ] && [ -s "$BODY" ] && ! grep -qi "ligne de commande" "$BODY"; then fail "script de test exécutable depuis le web (/scripts/test-comptage.php → 200)"
else pass "scripts de test non exécutables depuis le web ($STATUS)"; fi

# ── Essai de connexion bénévole (facultatif) ─────────────────────────────────
section "Connexion bénévole"
if [ -z "${COMPTAGE_CODE:-}" ]; then
    echo -e "  ${BLUE}ℹ${NC} non testé. Pour tester : ${BOLD}COMPTAGE_CODE=votre_code ./scripts/verify-deploy.sh${NC}"
else
    rm -f "$JAR"
    fetch GET "/comptage/login.php" -c "$JAR" -b "$JAR"
    token=$(grep -o 'name="csrf_token" value="[^"]*"' "$BODY" | head -1 | sed 's/.*value="//; s/"$//')
    if [ -z "$token" ]; then fail "jeton CSRF introuvable sur la page de connexion"
    else
        fetch POST "/comptage/login.php" -c "$JAR" -b "$JAR" --data-urlencode "csrf_token=$token" --data-urlencode "action=login" --data-urlencode "code=$COMPTAGE_CODE"
        loc=$(header location)
        if [ "$STATUS" = "302" ] && echo "$loc" | grep -q "index.php"; then
            pass "code accepté"
            fetch GET "/comptage/index.php" -c "$JAR" -b "$JAR"
            if [ "$STATUS" != "200" ]; then fail "compteur après connexion : réponse $STATUS"
            elif grep -q "window.CP = " "$BODY"; then
                pass "compteur affiché pour l'événement actif"
                [ "$(header x-cp-app)" = "1" ] && pass "en-tête X-Cp-App présent (mise en cache hors ligne)" || fail "en-tête X-Cp-App absent" "le service worker ne garderait pas la page pour le mode hors ligne"
                grep -q 'src="/assets/comptage-vision.js"' "$BODY" && pass "script du mode caméra référencé" || fail "script du mode caméra non référencé"
                csrf=$(grep -o '"csrf":"[^"]*"' "$BODY" | head -1 | sed 's/"csrf":"//; s/"$//')
                eid=$(grep -o '"eventId":[0-9]*' "$BODY" | head -1 | sed 's/.*://')
                fetch POST "/comptage/api.php" -c "$JAR" -b "$JAR" -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" -d "{\"event\":$eid,\"ops\":[]}"
                if [ "$STATUS" = "200" ] && grep -q '"ok":true' "$BODY"; then pass "API de synchronisation (lecture seule) : 200"
                else fail "API de synchronisation : réponse $STATUS" "$(head -c 160 "$BODY")"; fi
                fetch POST "/comptage/api.php" -c "$JAR" -b "$JAR" -H 'Content-Type: application/json' -d "{\"event\":$eid,\"ops\":[]}"
                [ "$STATUS" = "403" ] && pass "API sans jeton CSRF refusée (403)" || fail "API sans jeton CSRF : réponse $STATUS (403 attendu)"
            elif grep -q "Pas d.événement à compter" "$BODY"; then
                warn "connexion OK mais aucun événement actif" "créer un événement dans Administration → Comptage de foule → Événements & postes"
            else fail "compteur : contenu inattendu"; fi
        elif [ "$STATUS" = "200" ] && grep -q "Code incorrect" "$BODY"; then
            fail "code refusé" "ne pas insister : 5 essais ratés bloquent l'adresse IP pendant 15 minutes"
        elif [ "$STATUS" = "200" ] && grep -qi "Trop d.essais" "$BODY"; then
            warn "connexion temporairement bloquée (trop d'essais)" "réessayer dans 15 minutes"
        else fail "connexion : réponse $STATUS inattendue"; fi
    fi
fi

# ── Bilan ─────────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}════════════════════════════════════════════════${NC}"
if [ "$FAIL" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}  ✅ Déploiement vérifié : $PASS contrôles réussis${NC}$([ "$WARN" -gt 0 ] && echo -e ", ${YELLOW}$WARN avertissement(s)${NC}")"
else
    echo -e "${RED}${BOLD}  ❌ $FAIL échec(s)${NC}, $PASS réussi(s)$([ "$WARN" -gt 0 ] && echo -e ", ${YELLOW}$WARN avertissement(s)${NC}")"
fi
echo -e "${BOLD}════════════════════════════════════════════════${NC}"
echo "À faire à la main (impossible à vérifier à distance) : ouvrir le compteur sur un vrai téléphone,"
echo "« Ajouter à l'écran d'accueil », puis tester le mode caméra et le mode avion."
[ "$FAIL" -eq 0 ]
