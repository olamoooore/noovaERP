#!/usr/bin/env bash
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
BACKEND_ENV_FILE="$BACKEND_DIR/.env"

if [ -f "$BACKEND_ENV_FILE" ]; then
  # shellcheck disable=SC1090
  set -a
  . "$BACKEND_ENV_FILE"
  set +a
fi

API_BASE="${API_BASE:-http://localhost:8000/api/v1}"
DB_NAME="${DB_DATABASE:-noovaerp}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_USER="${DB_USERNAME:-postgres}"
DB_PASSWORD="${DB_PASSWORD:-postgres}"

STAMP="$(date +%s)"
TENANT_NAME="Invoice Flow Tenant ${STAMP}"
TENANT_EMAIL="invoice-flow-${STAMP}@example.com"
TENANT_COMPANY="Invoice Flow Co ${STAMP}"
TENANT_SUBDOMAIN="invoice-flow-${STAMP}"
TENANT_PASSWORD="Password123!"

PRODUCT_NAME="Invoice Flow Product ${STAMP}"
PRODUCT_SKU="FLOW-${STAMP}"
PRODUCT_COST="80.00"
PRODUCT_PRICE="123.45"

CUSTOMER_NAME="Invoice Flow Customer ${STAMP}"
CUSTOMER_EMAIL="customer-${STAMP}@example.com"

FAILURES=0
TENANT_ID=""
AUTH_TOKEN=""
PRODUCT_ID=""
CUSTOMER_ID=""
INVOICE_ID=""

require_cmd() {
  local cmd="$1"
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "Missing required command: $cmd" >&2
    exit 1
  fi
}

json_get() {
  local path="$1"
  php -r '
    $path = $argv[1];
    $payload = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($payload)) {
        exit(1);
    }

    $value = $payload;
    foreach (explode(".", $path) as $segment) {
        if (is_array($value) && array_key_exists($segment, $value)) {
            $value = $value[$segment];
            continue;
        }

        exit(1);
    }

    if (is_array($value) || is_object($value)) {
        echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    if ($value !== null) {
        echo $value;
    }
  ' "$path"
}

print_response() {
  local label="$1"
  local status="$2"
  local body="$3"

  printf '\n[%s]\nHTTP %s\n%s\n' "$label" "$status" "$body"
}

curl_json() {
  local label="$1"
  local method="$2"
  local url="$3"
  local token="${4:-}"
  local data="${5:-}"

  local args=(-sS -X "$method" "$url" -H 'Accept: application/json' -H 'Content-Type: application/json')
  if [ -n "$token" ]; then
    args+=(-H "Authorization: Bearer ${token}")
  fi
  if [ -n "$data" ]; then
    args+=(--data "$data")
  fi

  local response status body
  response="$(curl "${args[@]}" -w $'\n%{http_code}')"
  status="${response##*$'\n'}"
  body="${response%$'\n'*}"

  print_response "$label" "$status" "$body"

  CURL_STATUS="$status"
  CURL_BODY="$body"
}

require_cmd curl
require_cmd php
require_cmd psql

signup_payload="$(cat <<JSON
{
  "name": "${TENANT_NAME}",
  "email": "${TENANT_EMAIL}",
  "company": "${TENANT_COMPANY}",
  "subdomain": "${TENANT_SUBDOMAIN}",
  "password": "${TENANT_PASSWORD}"
}
JSON
)"

curl_json "1. signup" POST "${API_BASE}/signup" "" "$signup_payload"
if [[ "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  TENANT_ID="$(printf '%s' "$CURL_BODY" | json_get 'tenant.id' 2>/dev/null || true)"
fi
if [[ ! "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  ((FAILURES++))
fi

login_payload="$(cat <<JSON
{
  "email": "${TENANT_EMAIL}",
  "password": "${TENANT_PASSWORD}"
}
JSON
)"

curl_json "2. login" POST "${API_BASE}/auth/login" "" "$login_payload"
if [[ "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  AUTH_TOKEN="$(printf '%s' "$CURL_BODY" | json_get 'token' 2>/dev/null || true)"
  if [ -z "$TENANT_ID" ]; then
    TENANT_ID="$(printf '%s' "$CURL_BODY" | json_get 'tenant.id' 2>/dev/null || true)"
  fi
fi
if [[ ! "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  ((FAILURES++))
fi

product_payload="$(cat <<JSON
{
  "name": "${PRODUCT_NAME}",
  "sku": "${PRODUCT_SKU}",
  "type": "product",
  "cost": ${PRODUCT_COST},
  "price": ${PRODUCT_PRICE},
  "track_inventory": true,
  "stock_quantity": 10,
  "is_active": true
}
JSON
)"

curl_json "3. create product" POST "${API_BASE}/products" "$AUTH_TOKEN" "$product_payload"
if [[ "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  PRODUCT_ID="$(printf '%s' "$CURL_BODY" | json_get 'id' 2>/dev/null || true)"
fi
if [[ ! "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  ((FAILURES++))
fi

customer_payload="$(cat <<JSON
{
  "name": "${CUSTOMER_NAME}",
  "email": "${CUSTOMER_EMAIL}",
  "company": "${TENANT_COMPANY}",
  "type": "company",
  "phone": "+1555000${STAMP: -4}",
  "is_active": true
}
JSON
)"

curl_json "4. create customer" POST "${API_BASE}/customers" "$AUTH_TOKEN" "$customer_payload"
if [[ "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  CUSTOMER_ID="$(printf '%s' "$CURL_BODY" | json_get 'id' 2>/dev/null || true)"
fi
if [[ ! "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  ((FAILURES++))
fi

invoice_payload="$(cat <<JSON
{
  "customer_id": ${CUSTOMER_ID:-0},
  "invoice_date": "$(date +%F)",
  "due_date": "$(date -d '+30 days' +%F 2>/dev/null || date +%F)",
  "customer_reference": "FLOW-${STAMP}",
  "notes": "End-to-end invoice flow smoke test",
  "lines": [
    {
      "product_id": ${PRODUCT_ID:-0},
      "description": "${PRODUCT_NAME}",
      "quantity": 1,
      "unit_price": ${PRODUCT_PRICE},
      "tax_rate": 0,
      "discount_amount": 0
    }
  ]
}
JSON
)"

curl_json "5. create invoice" POST "${API_BASE}/invoices" "$AUTH_TOKEN" "$invoice_payload"
if [[ "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  INVOICE_ID="$(printf '%s' "$CURL_BODY" | json_get 'id' 2>/dev/null || true)"
fi
if [[ ! "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  ((FAILURES++))
fi

curl_json "6. post invoice" POST "${API_BASE}/invoices/${INVOICE_ID}/send" "$AUTH_TOKEN"
if [[ ! "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  ((FAILURES++))
fi

payment_payload="$(cat <<JSON
{
  "invoice_id": ${INVOICE_ID:-0},
  "payment_date": "$(date +%F)",
  "amount": ${PRODUCT_PRICE},
  "method": "cash",
  "reference": "PAY-${STAMP}",
  "notes": "End-to-end invoice flow payment"
}
JSON
)"

curl_json "7. record payment" POST "${API_BASE}/payments" "$AUTH_TOKEN" "$payment_payload"
if [[ ! "$CURL_STATUS" =~ ^2[0-9][0-9]$ ]]; then
  ((FAILURES++))
fi

if [ -z "$TENANT_ID" ]; then
  echo "Unable to determine tenant schema for SQL check" >&2
  exit 1
fi

echo
echo "[8. ledger balance sql]"
PGPASSWORD="$DB_PASSWORD" PGHOST="$DB_HOST" PGPORT="$DB_PORT" PGUSER="$DB_USER" PGOPTIONS="-c search_path=\"${TENANT_ID}\"" \
  psql -qAtX -d "$DB_NAME" -c "SELECT COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c FROM journal_entry_lines jel JOIN journal_entries je ON jel.journal_entry_id=je.id WHERE je.status='posted';"

exit "$FAILURES"
