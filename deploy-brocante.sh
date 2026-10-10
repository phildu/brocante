#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# deploy-brocante.sh — Déploiement local vers OVH d'un commerce (marque blanche)
#
# Commerce déployé : --tenant <slug>, sinon variable TENANT, sinon petit-chalet.
# Identifiants FTP : .env.deploy.<slug> (ou .env.deploy pour petit-chalet).
# Seuls le code, la config de ce commerce (tenants/<slug>, assets/tenants/<slug>)
# et sa base sont envoyés ; un fichier .tenant indique au serveur quel commerce servir.
#
# Mode portail (--portail) : déploie TOUS les commerces et le portail de
# gestion dans un même dossier (ex. brocs.arrimage.com, chaque commerce sur
# <slug>.brocs.arrimage.com). Identifiants dans .env.deploy.portail :
#   FTP_SERVER, FTP_USER, FTP_PASS, FTP_PATH_FRONT (ex. /www/brocs/),
#   URL_FRONT (ex. https://brocs.arrimage.com),
#   PORTAIL_USER, PORTAIL_PASSWORD (compte de connexion au portail en ligne).
#
# Usage :
#   ./deploy-brocante.sh --portail            → portail + tous les commerces (bases non touchées)
#   ./deploy-brocante.sh --portail --with-db  → idem + envoie les bases locales (1er déploiement)
#   ./deploy-brocante.sh           → code uniquement (brocante.db JAMAIS touché,
#                                     pour ne pas écraser les commandes/données
#                                     réelles accumulées en ligne depuis le
#                                     dernier déploiement)
#   ./deploy-brocante.sh --tenant exemple-librairie → déploie un autre commerce
#   ./deploy-brocante.sh --with-db → envoie EN PLUS la base (brocante.db) telle quelle en
#                                     local, en écrasant celui du serveur.
#                                     À utiliser UNIQUEMENT pour le tout premier
#                                     déploiement (base vide côté serveur), ou
#                                     si vous voulez délibérément resynchroniser
#                                     le serveur depuis le local.
#
# Prérequis : lftp installé (brew install lftp), .env.deploy rempli
# (même compte FTP OVH que louxor/electroboy80 — voir ces projets)
# ─────────────────────────────────────────────────────────────────────────────

set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# ── Couleurs ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

log()  { echo -e "${BLUE}▶${NC} $1"; }
ok()   { echo -e "${GREEN}✅${NC} $1"; }
warn() { echo -e "${YELLOW}⚠️ ${NC} $1"; }
err()  { echo -e "${RED}❌${NC} $1"; exit 1; }

WITH_DB=0
PORTAIL=0
TENANT="${TENANT:-petit-chalet}"
while [ $# -gt 0 ]; do
    case "$1" in
        --with-db) WITH_DB=1 ;;
        --portail) PORTAIL=1 ;;
        --tenant) TENANT="$2"; shift ;;
        *) err "Option inconnue : $1" ;;
    esac
    shift
done
[ -f "tenants/$TENANT/tenant.php" ] || err "Commerce introuvable : tenants/$TENANT/tenant.php"
export TENANT

# Base et envoi des photos : lus dans la config du commerce.
DB_REL="$(php -r 'require "config.php"; echo substr(DB_PATH, strlen(__DIR__) + 1);')"
DEPLOY_UPLOADS="$(php -r 'require "config.php"; echo tenant("deploy_uploads") ? 1 : 0;')"

# ── Charger les credentials ───────────────────────────────────────────────────
ENV_FILE=".env.deploy.$TENANT"
[ "$PORTAIL" = "1" ] && ENV_FILE=".env.deploy.portail"
if [ ! -f "$ENV_FILE" ] && [ "$TENANT" = "petit-chalet" ] && [ "$PORTAIL" = "0" ]; then
    ENV_FILE=".env.deploy"
fi
if [ ! -f "$ENV_FILE" ]; then
    err "$ENV_FILE introuvable. Voir l'en-tête de ce script pour le format attendu."
fi
source "$ENV_FILE"

[ -z "$FTP_SERVER"     ] && err "FTP_SERVER manquant dans .env.deploy"
[ -z "$FTP_USER"       ] && err "FTP_USER manquant dans .env.deploy"
[ -z "$FTP_PASS"       ] && err "FTP_PASS manquant dans .env.deploy"
[ -z "$FTP_PATH_FRONT" ] && err "FTP_PATH_FRONT manquant dans .env.deploy"

command -v lftp >/dev/null 2>&1 || err "lftp non installé. Lance : brew install lftp"
if [ "$PORTAIL" = "1" ]; then
    [ -z "$PORTAIL_USER" ] && err "PORTAIL_USER manquant dans $ENV_FILE"
    [ ${#PORTAIL_PASSWORD} -lt 10 ] && err "PORTAIL_PASSWORD manquant ou trop court (10 caractères minimum) dans $ENV_FILE"
fi

# Fichier « accès interdit » déposé dans les dossiers sensibles du serveur.
DENY_FILE="$(mktemp)"
printf 'Require all denied\n' > "$DENY_FILE"
trap 'rm -f "$DENY_FILE"' EXIT

echo ""
echo -e "${BOLD}════════════════════════════════════════════════${NC}"
if [ "$PORTAIL" = "1" ]; then
    echo -e "${BOLD}  DÉPLOIEMENT PREPROD — OVH — portail + tous les commerces${NC}"
else
    echo -e "${BOLD}  DÉPLOIEMENT PREPROD — OVH — commerce : ${TENANT}${NC}"
fi
echo -e "${BOLD}  Front : ${URL_FRONT}${NC}"
if [ "$WITH_DB" = "1" ]; then
    echo -e "${YELLOW}  ⚠️  --with-db : ${DB_REL} du serveur va être ÉCRASÉ par la version locale${NC}"
else
    echo -e "${BLUE}  ${DB_REL} non touché sur le serveur (utilisez --with-db pour l'envoyer)${NC}"
fi
echo -e "${BOLD}════════════════════════════════════════════════${NC}"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# DÉPLOYER LE FRONTEND (PHP + SQLite)
# ─────────────────────────────────────────────────────────────────────────────
deploy_front() {
    log "Déploiement vers ${FTP_PATH_FRONT}..."

    local uploads_exclude=""
    [ "$DEPLOY_UPLOADS" = "1" ] || uploads_exclude="--exclude-glob uploads/"
    local tenant_file
    tenant_file="$(mktemp)"
    printf '%s\n' "$TENANT" > "$tenant_file"
    # Lecture des clés partagées d'un autre déploiement (SHARED_SECRETS_FROM=../brocs dans le fichier .env.deploy du commerce).
    local shared_link_cmd=""
    if [ -n "$SHARED_SECRETS_FROM" ]; then
        local shared_link
        shared_link="$(mktemp)"
        printf '%s\n' "$SHARED_SECRETS_FROM" > "$shared_link"
        shared_link_cmd="put $shared_link -o ${FTP_PATH_FRONT}.shared-secrets-from"
    fi

    lftp -c "
set ftp:ssl-allow yes
set ftp:ssl-force no
set net:timeout 30
set net:max-retries 3
set mirror:parallel-directories yes
open ftp://$FTP_USER:$FTP_PASS@$FTP_SERVER

mirror --reverse --no-perms --no-umask \
  --exclude-glob .git/ \
  --exclude-glob .github/ \
  --exclude-glob .claude/ \
  --exclude-glob modal/ \
  --exclude-glob LocalValetDriver.php \
  --exclude-glob .venv/ \
  --exclude-glob .secrets/ \
  --exclude-glob var/log/ \
  --exclude-glob uploads/import/ \
  --exclude-glob uploads/batch-import/ \
  --exclude-glob node_modules/ \
  --exclude-glob db-backup/ \
  --exclude-glob '*.log' \
  --exclude-glob .env* \
  --exclude-glob config.local.php \
  --exclude-glob deploy-brocante.sh \
  --exclude-glob '*.sql' \
  --exclude-glob '*.bak' \
  --exclude-glob '*.db' \
  --exclude-glob '*.db.bak-*' \
  --exclude-glob .DS_Store \
  --exclude-glob .tenant \
  --exclude-glob .shared-secrets-from \
  --exclude-glob portail/ \
  --exclude-glob accueil/ \
  --exclude-glob annuaire/ \
  --exclude-glob inscription/ \
  --exclude-glob galerie/ \
  --exclude-glob data/ \
  --exclude-glob tenants/ \
  --exclude-glob assets/tenants/ \
  $uploads_exclude \
  --verbose \
  . ${FTP_PATH_FRONT}
mirror --reverse --no-perms --no-umask --verbose tenants/$TENANT ${FTP_PATH_FRONT}tenants/$TENANT
put tenants/.htaccess -o ${FTP_PATH_FRONT}tenants/.htaccess
$( [ -d "assets/tenants/$TENANT" ] && echo "mirror --reverse --no-perms --no-umask --verbose assets/tenants/$TENANT ${FTP_PATH_FRONT}assets/tenants/$TENANT" )
put $tenant_file -o ${FTP_PATH_FRONT}.tenant
$shared_link_cmd
mkdir -p -f ${FTP_PATH_FRONT}.secrets
put $DENY_FILE -o ${FTP_PATH_FRONT}.secrets/.htaccess
mkdir -p -f ${FTP_PATH_FRONT}data
put $DENY_FILE -o ${FTP_PATH_FRONT}data/.htaccess

quit
" 2>&1 | grep -v "^$" | while read line; do
        echo -e "  ${BLUE}→${NC} $line"
    done

    # Permissions uploads/ et var/ (écriture nécessaire : photos, logs de génération)
    # — non bloquant : lftp continue même si chmod échoue (droits déjà bons par défaut sur OVH).
    lftp -c "
open ftp://$FTP_USER:$FTP_PASS@$FTP_SERVER
chmod 755 ${FTP_PATH_FRONT}uploads
chmod 755 ${FTP_PATH_FRONT}var
quit
" 2>/dev/null || true

    rm -f "$tenant_file"
    ok "Frontend déployé → ${URL_FRONT}"
}

# ─────────────────────────────────────────────────────────────────────────────
# DÉPLOYER LE PORTAIL (tous les commerces + portail/, compte de connexion)
# ─────────────────────────────────────────────────────────────────────────────
deploy_portail() {
    log "Déploiement du portail et de tous les commerces vers ${FTP_PATH_FRONT}..."

    local db_exclude="--exclude-glob data/ --exclude-glob *.db"
    [ "$WITH_DB" = "1" ] && db_exclude=""
    local account_file
    account_file="$(mktemp)"
    PORTAIL_USER="$PORTAIL_USER" PORTAIL_PASSWORD="$PORTAIL_PASSWORD" php -r '
        echo json_encode(["user" => getenv("PORTAIL_USER"), "password_hash" => password_hash(getenv("PORTAIL_PASSWORD"), PASSWORD_DEFAULT)]);
    ' > "$account_file"

    lftp -c "
set ftp:ssl-allow yes
set ftp:ssl-force no
set net:timeout 30
set net:max-retries 3
set mirror:parallel-directories yes
open ftp://$FTP_USER:$FTP_PASS@$FTP_SERVER

mirror --reverse --no-perms --no-umask \
  --exclude-glob .git/ \
  --exclude-glob .github/ \
  --exclude-glob .claude/ \
  --exclude-glob modal/ \
  --exclude-glob LocalValetDriver.php \
  --exclude-glob .venv/ \
  --exclude-glob .secrets/ \
  --exclude-glob var/log/ \
  --exclude-glob uploads/import/ \
  --exclude-glob uploads/batch-import/ \
  --exclude-glob node_modules/ \
  --exclude-glob db-backup/ \
  --exclude-glob '*.log' \
  --exclude-glob .env* \
  --exclude-glob config.local.php \
  --exclude-glob deploy-brocante.sh \
  --exclude-glob '*.bak' \
  --exclude-glob '*.db.bak-*' \
  --exclude-glob .DS_Store \
  --exclude-glob .tenant \
  $db_exclude \
  --verbose \
  . ${FTP_PATH_FRONT}
mkdir -p -f ${FTP_PATH_FRONT}.secrets
put $account_file -o ${FTP_PATH_FRONT}.secrets/portail.json
put $DENY_FILE -o ${FTP_PATH_FRONT}.secrets/.htaccess
mkdir -p -f ${FTP_PATH_FRONT}data
put $DENY_FILE -o ${FTP_PATH_FRONT}data/.htaccess
put tenants/.htaccess -o ${FTP_PATH_FRONT}tenants/.htaccess

quit
" 2>&1 | grep -v "^$" | while read line; do
        echo -e "  ${BLUE}→${NC} $line"
    done

    lftp -c "
open ftp://$FTP_USER:$FTP_PASS@$FTP_SERVER
chmod 600 ${FTP_PATH_FRONT}.secrets/portail.json
chmod 755 ${FTP_PATH_FRONT}uploads
chmod 755 ${FTP_PATH_FRONT}data
chmod 755 ${FTP_PATH_FRONT}tenants
chmod 755 ${FTP_PATH_FRONT}assets/tenants
quit
" 2>/dev/null || true

    rm -f "$account_file"
    ok "Portail déployé → ${URL_FRONT}/portail/"
}

# ─────────────────────────────────────────────────────────────────────────────
# ENVOYER la base (uniquement avec --with-db — écrase la base du serveur)
# ─────────────────────────────────────────────────────────────────────────────
deploy_db() {
    [ ! -f "$DB_REL" ] && err "$DB_REL introuvable en local."
    warn "Envoi de $DB_REL — la base du serveur va être remplacée par celle-ci."
    lftp -c "
set ftp:ssl-allow yes
set ftp:ssl-force no
open ftp://$FTP_USER:$FTP_PASS@$FTP_SERVER
mkdir -p -f ${FTP_PATH_FRONT}$(dirname "$DB_REL")
put $DB_REL -o ${FTP_PATH_FRONT}$DB_REL
chmod 664 ${FTP_PATH_FRONT}$DB_REL
quit
" 2>/dev/null || true
    ok "$DB_REL envoyé → ${FTP_PATH_FRONT}$DB_REL"
}

# ─────────────────────────────────────────────────────────────────────────────
# SNAPSHOT GIT — point de restauration avant chaque déploiement (no-op si pas de repo)
# ─────────────────────────────────────────────────────────────────────────────
git_snapshot() {
    if ! git rev-parse --git-dir > /dev/null 2>&1; then return; fi
    local changes
    changes=$(git status --porcelain 2>/dev/null)
    if [ -z "$changes" ]; then
        log "Git : rien de nouveau à commiter"
        return
    fi
    local label="deploy-preprod $(date '+%Y-%m-%d %H:%M')"
    git add -A
    git commit -m "$label" --quiet && ok "Git snapshot : \"$label\"" || warn "Git commit échoué (non bloquant)"
    git push origin main --quiet 2>/dev/null && ok "Git push → GitHub" || warn "Git push échoué (non bloquant, pas de remote configuré)"
}

# ─────────────────────────────────────────────────────────────────────────────
# LANCEMENT
# ─────────────────────────────────────────────────────────────────────────────
START=$(date +%s)

git_snapshot
if [ "$PORTAIL" = "1" ]; then
    deploy_portail
else
    deploy_front
    [ "$WITH_DB" = "1" ] && deploy_db
fi

END=$(date +%s)
ELAPSED=$((END - START))

echo ""
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════${NC}"
echo -e "${GREEN}${BOLD}  ✅ DÉPLOIEMENT TERMINÉ en ${ELAPSED}s${NC}"
echo -e "${GREEN}${BOLD}  🌐 Front : ${URL_FRONT}${NC}"
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════${NC}"
echo ""
if [ "$PORTAIL" = "1" ]; then
    echo "Portail : ${URL_FRONT}/portail/ (identifiant ${PORTAIL_USER})"
    echo "Chaque commerce : ${URL_FRONT}/<identifiant>/ — aucun domaine ni sous-domaine à ajouter chez OVH"
    echo "  (seul ${URL_FRONT#*://} doit exister : Hébergement → Multisite, dossier racine ${FTP_PATH_FRONT#/}, SSL activé)."
    echo "Les bases absentes du serveur sont créées automatiquement à la première visite."
    echo ""
elif [ "$WITH_DB" = "0" ]; then
    echo -e "${YELLOW}Rappel : ${DB_REL} n'a pas été envoyé (défaut). Pour le tout premier${NC}"
    echo -e "${YELLOW}déploiement, relancez avec : ./deploy-brocante.sh --tenant ${TENANT} --with-db${NC}"
    echo ""
fi
echo "À vérifier côté serveur si ce n'est pas déjà fait :"
echo "  1. Le dossier ${FTP_PATH_FRONT} et son sous-dossier uploads/ sont inscriptibles"
echo "     par PHP (chmod 755 ou 775 selon l'hébergement)."
echo "  2. Clés Stripe/Gemini renseignées via Administration → Réglages Stripe,"
echo "     ou déposées à la main dans .secrets/ sur le serveur."
