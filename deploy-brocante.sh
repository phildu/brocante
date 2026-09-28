#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# deploy-brocante.sh — Déploiement local vers OVH (preprod La Brocante du Petit Chalet)
#
# Usage :
#   ./deploy-brocante.sh           → code uniquement (brocante.db JAMAIS touché,
#                                     pour ne pas écraser les commandes/données
#                                     réelles accumulées en ligne depuis le
#                                     dernier déploiement)
#   ./deploy-brocante.sh --with-db → envoie EN PLUS brocante.db tel quel en
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
[ "$1" = "--with-db" ] && WITH_DB=1

# ── Charger les credentials ───────────────────────────────────────────────────
if [ ! -f ".env.deploy" ]; then
    err ".env.deploy introuvable. Voir l'en-tête de ce script pour le format attendu."
fi
source .env.deploy

[ -z "$FTP_SERVER"     ] && err "FTP_SERVER manquant dans .env.deploy"
[ -z "$FTP_USER"       ] && err "FTP_USER manquant dans .env.deploy"
[ -z "$FTP_PASS"       ] && err "FTP_PASS manquant dans .env.deploy"
[ -z "$FTP_PATH_FRONT" ] && err "FTP_PATH_FRONT manquant dans .env.deploy"

command -v lftp >/dev/null 2>&1 || err "lftp non installé. Lance : brew install lftp"

echo ""
echo -e "${BOLD}════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  DÉPLOIEMENT PREPROD — OVH${NC}"
echo -e "${BOLD}  Front : ${URL_FRONT}${NC}"
if [ "$WITH_DB" = "1" ]; then
    echo -e "${YELLOW}  ⚠️  --with-db : brocante.db du serveur va être ÉCRASÉ par la version locale${NC}"
else
    echo -e "${BLUE}  brocante.db non touché sur le serveur (utilisez --with-db pour l'envoyer)${NC}"
fi
echo -e "${BOLD}════════════════════════════════════════════════${NC}"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# DÉPLOYER LE FRONTEND (PHP + SQLite)
# ─────────────────────────────────────────────────────────────────────────────
deploy_front() {
    log "Déploiement vers ${FTP_PATH_FRONT}..."

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
  --exclude-glob .venv/ \
  --exclude-glob .secrets/ \
  --exclude-glob var/log/ \
  --exclude-glob uploads/import/ \
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
  --verbose \
  . ${FTP_PATH_FRONT}

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

    ok "Frontend déployé → ${URL_FRONT}"
}

# ─────────────────────────────────────────────────────────────────────────────
# ENVOYER brocante.db (uniquement avec --with-db — écrase la base du serveur)
# ─────────────────────────────────────────────────────────────────────────────
deploy_db() {
    [ ! -f "brocante.db" ] && err "brocante.db introuvable en local."
    warn "Envoi de brocante.db — la base du serveur va être remplacée par celle-ci."
    lftp -c "
set ftp:ssl-allow yes
set ftp:ssl-force no
open ftp://$FTP_USER:$FTP_PASS@$FTP_SERVER
put brocante.db -o ${FTP_PATH_FRONT}brocante.db
chmod 664 ${FTP_PATH_FRONT}brocante.db
quit
" 2>/dev/null || true
    ok "brocante.db envoyé → ${FTP_PATH_FRONT}brocante.db"
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
deploy_front
[ "$WITH_DB" = "1" ] && deploy_db

END=$(date +%s)
ELAPSED=$((END - START))

echo ""
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════${NC}"
echo -e "${GREEN}${BOLD}  ✅ DÉPLOIEMENT TERMINÉ en ${ELAPSED}s${NC}"
echo -e "${GREEN}${BOLD}  🌐 Front : ${URL_FRONT}${NC}"
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════${NC}"
echo ""
if [ "$WITH_DB" = "0" ]; then
    echo -e "${YELLOW}Rappel : brocante.db n'a pas été envoyé (défaut). Pour le tout premier${NC}"
    echo -e "${YELLOW}déploiement, relancez avec : ./deploy-brocante.sh --with-db${NC}"
    echo ""
fi
echo "À vérifier côté serveur si ce n'est pas déjà fait :"
echo "  1. Le dossier ${FTP_PATH_FRONT} et son sous-dossier uploads/ sont inscriptibles"
echo "     par PHP (chmod 755 ou 775 selon l'hébergement)."
echo "  2. Clés Stripe/Gemini renseignées via Administration → Réglages Stripe,"
echo "     ou déposées à la main dans .secrets/ sur le serveur."
