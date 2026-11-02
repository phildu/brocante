#!/usr/bin/env bash
# Teste un endpoint webhook Stripe avec curl, en signant le payload comme Stripe
# (HMAC SHA256 sur "<timestamp>.<payload>"). Le secret n'est jamais affiché ni
# passé en argument de commande : il vient de l'environnement ou d'un fichier.
#
# Exemples :
#   scripts/test-webhook.sh --shop la-friperie-de-naty --unsigned
#   scripts/test-webhook.sh --shop la-friperie-de-naty --secret-file ~/.whsec-naty
#   scripts/test-webhook.sh --shop la-friperie-de-naty --secret-file ~/.whsec-naty --replay
#   scripts/test-webhook.sh --url http://127.0.0.1:8124/ --secret-file /tmp/secret --event payment_intent.created
#
# Sans --secret-file ni STRIPE_WEBHOOK_SECRET, le script cherche le secret local
# dans .secrets/<boutique>/stripe_webhook.key puis .secrets/stripe_webhook.key.
#
# L'événement par défaut (payment_intent.created) est inerte : il est seulement
# noté dans stripe_events. N'envoyez pas d'identifiant de vraie session ou de
# vrai paiement sans savoir ce que vous faites.
set -euo pipefail
umask 077

ROOT=$(cd "$(dirname "$0")/.." && pwd)
BASE="https://brocs.arrimage.com"
URL=""
SHOP=""
SECRET_FILE="${STRIPE_WEBHOOK_SECRET_FILE:-}"
EVENT="payment_intent.created"
PAYLOAD_FILE=""
EVENT_ID=""
MODE="signed"
REPLAY=0
EXPECT=""
TIMEOUT=20

usage() {
  awk 'NR>1 { if ($0 !~ /^#/) exit; sub(/^# ?/, ""); print }' "$0"
  cat <<'TXT'

Options :
  --shop SLUG             boutique (l'URL devient <base>/<slug>/stripe-webhook.php)
  --base URL              base de la plateforme (défaut : https://brocs.arrimage.com)
  --url URL               URL complète de l'endpoint (remplace --shop/--base)
  --secret-file FICHIER   fichier contenant le whsec_... (ou variable STRIPE_WEBHOOK_SECRET)
  --event TYPE            type d'événement (défaut : payment_intent.created, inerte)
  --event-id ID           identifiant d'événement (défaut : evt_test_<horodatage>)
  --payload FICHIER       corps JSON complet (remplace --event/--event-id)
  --unsigned              POST sans en-tête Stripe-Signature (doit répondre 400)
  --bad-signature         POST avec une signature volontairement fausse (doit répondre 400)
  --replay                envoie deux fois le même événement (teste l'idempotence)
  --expect CODE           code HTTP attendu ; sort en erreur sinon
  --timeout SECONDES      délai curl (défaut : 20)
  -h, --help              cette aide
TXT
}

need() { [ -n "${2:-}" ] || { echo "option $1 : valeur manquante" >&2; exit 2; }; }

while [ $# -gt 0 ]; do
  case "$1" in
    --shop) need "$1" "${2:-}"; SHOP=$2; shift 2 ;;
    --base) need "$1" "${2:-}"; BASE=${2%/}; shift 2 ;;
    --url) need "$1" "${2:-}"; URL=$2; shift 2 ;;
    --secret-file) need "$1" "${2:-}"; SECRET_FILE=$2; shift 2 ;;
    --event) need "$1" "${2:-}"; EVENT=$2; shift 2 ;;
    --event-id) need "$1" "${2:-}"; EVENT_ID=$2; shift 2 ;;
    --payload) need "$1" "${2:-}"; PAYLOAD_FILE=$2; shift 2 ;;
    --unsigned) MODE="unsigned"; shift ;;
    --bad-signature) MODE="bad"; shift ;;
    --replay) REPLAY=1; shift ;;
    --expect) need "$1" "${2:-}"; EXPECT=$2; shift 2 ;;
    --timeout) need "$1" "${2:-}"; TIMEOUT=$2; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "option inconnue : $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [ -z "$URL" ]; then
  [ -n "$SHOP" ] || { echo "indiquez --shop ou --url" >&2; exit 2; }
  [[ "$SHOP" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { echo "slug de boutique invalide" >&2; exit 2; }
  URL="$BASE/$SHOP/stripe-webhook.php"
fi

secret=""
if [ "$MODE" != "unsigned" ]; then
  if [ -n "${STRIPE_WEBHOOK_SECRET:-}" ]; then
    secret=$STRIPE_WEBHOOK_SECRET
  elif [ -n "$SECRET_FILE" ]; then
    [ -r "$SECRET_FILE" ] || { echo "secret illisible : $SECRET_FILE" >&2; exit 2; }
    secret=$(tr -d '\r\n' < "$SECRET_FILE")
  elif [ -n "$SHOP" ] && [ -r "$ROOT/.secrets/$SHOP/stripe_webhook.key" ]; then
    secret=$(tr -d '\r\n' < "$ROOT/.secrets/$SHOP/stripe_webhook.key")
  elif [ -n "$SHOP" ] && [ -r "$ROOT/.secrets/stripe_webhook.key" ]; then
    secret=$(tr -d '\r\n' < "$ROOT/.secrets/stripe_webhook.key")
  else
    echo "aucun secret : --secret-file, STRIPE_WEBHOOK_SECRET ou .secrets/<boutique>/stripe_webhook.key" >&2
    exit 2
  fi
  [ -n "$secret" ] || { echo "secret vide" >&2; exit 2; }
fi

PAYLOAD_TMP=$(mktemp)
RESP_TMP=$(mktemp)
trap 'rm -f "$PAYLOAD_TMP" "$RESP_TMP"' EXIT

if [ -n "$PAYLOAD_FILE" ]; then
  [ -r "$PAYLOAD_FILE" ] || { echo "payload illisible : $PAYLOAD_FILE" >&2; exit 2; }
  payload=$(cat "$PAYLOAD_FILE")
else
  [[ "$EVENT" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "type d'événement invalide" >&2; exit 2; }
  EVENT_ID=${EVENT_ID:-evt_test_$(date +%s)_$RANDOM}
  [[ "$EVENT_ID" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "identifiant d'événement invalide" >&2; exit 2; }
  payload=$(printf '{"id":"%s","object":"event","type":"%s","data":{"object":{"id":"pi_test_%s","object":"payment_intent","metadata":{"type":"shop_order","shop":"%s"}}}}' \
    "$EVENT_ID" "$EVENT" "$EVENT_ID" "${SHOP:-unknown}")
fi
printf '%s' "$payload" > "$PAYLOAD_TMP"

send() {
  local header=$1
  local -a args=(-sS -o "$RESP_TMP" -w '%{http_code}' --max-time "$TIMEOUT" -X POST
    -H 'Content-Type: application/json' --data-binary "@$PAYLOAD_TMP")
  [ -n "$header" ] && args+=(-H "Stripe-Signature: $header")
  curl "${args[@]}" "$URL"
}

run_once() {
  local label=$1 header="" code body
  if [ "$MODE" = "signed" ]; then
    local ts signed sig
    ts=$(date +%s)
    signed="$ts.$payload"
    sig=$(printf '%s' "$signed" | openssl dgst -sha256 -hmac "$secret" | sed 's/^.*= //')
    header="t=$ts,v1=$sig"
  elif [ "$MODE" = "bad" ]; then
    header="t=$(date +%s),v1=0000000000000000000000000000000000000000000000000000000000000000"
  fi

  code=$(send "$header")
  body=$(cat "$RESP_TMP")
  printf '%s→ POST %s [%s]\n' "${label:+$label }" "$URL" "$MODE"
  printf '%s← HTTP %s : %s\n' "${label:+$label }" "$code" "$body"

  if [ -n "$EXPECT" ] && [ "$code" != "$EXPECT" ]; then
    echo "attendu : HTTP $EXPECT, reçu : HTTP $code" >&2
    return 1
  fi
}

rc=0
run_once "" || rc=1
if [ "$REPLAY" = "1" ]; then
  run_once "rejeu" || rc=1
fi
exit "$rc"
