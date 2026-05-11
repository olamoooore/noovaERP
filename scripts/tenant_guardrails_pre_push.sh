#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND_DIR="$REPO_ROOT/backend"

if [[ ! -f "$BACKEND_DIR/artisan" ]]; then
  echo "[tenant-guardrails] backend/artisan not found; skipping guardrails check"
  exit 0
fi

echo "[tenant-guardrails] running audit..."
cd "$BACKEND_DIR"

# Audit mode only. If issues are found this exits non-zero and blocks push.
php artisan ops:tenant-guardrails

echo "[tenant-guardrails] audit passed"
