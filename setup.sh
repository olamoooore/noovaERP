#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
FRONTEND_DIR="$ROOT_DIR/frontend"
BACKUP_FILE="$ROOT_DIR/noovaerp_current_backup.sql"
BACKEND_LOG="/tmp/backend.log"
FRONTEND_LOG="/tmp/frontend.log"
DB_NAME="noovaerp"

mkdir -p /tmp

if [ ! -f "$BACKUP_FILE" ]; then
  echo "Backup file not found: $BACKUP_FILE" >&2
  exit 1
fi

if ! command -v createdb >/dev/null 2>&1; then
  echo "createdb is required" >&2
  exit 1
fi

if ! command -v psql >/dev/null 2>&1; then
  echo "psql is required" >&2
  exit 1
fi

cd "$BACKEND_DIR"

createdb "$DB_NAME" 2>/dev/null || true
psql -d "$DB_NAME" -f "$BACKUP_FILE"

composer install

cd "$FRONTEND_DIR"
npm install

cd "$BACKEND_DIR"
php artisan migrate
php artisan tenants:migrate

nohup php artisan serve > "$BACKEND_LOG" 2>&1 &
nohup npm run dev > "$FRONTEND_LOG" 2>&1 &

echo "Backend log: $BACKEND_LOG"
echo "Frontend log: $FRONTEND_LOG"
