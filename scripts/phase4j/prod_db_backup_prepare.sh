#!/usr/bin/env bash
# Phase 4J — encrypted production DB backup procedure (prepare only until approved).
# Does not modify application code. Does not run migrations.
set -euo pipefail

APP="${APP_ROOT:-/var/www/mediguide}"
BACKUP_DIR="${BACKUP_DIR:-/home/mediguide/secure-backups/phase4j}"
APPROVAL_REQUIRED='APPROVED backup MediGuide production database'

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  exit 2
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR" /home/mediguide/secure-backups
STAMP=$(date -u +%Y%m%d%H%M%S)
SQL_PATH="$BACKUP_DIR/mediguide_demo_${STAMP}.sql"
ENC_PATH="$SQL_PATH.enc"
PASS_PATH="$BACKUP_DIR/.dump_pass_${STAMP}"

cd "$APP"
DB_NAME=$(php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("database.connections.mysql.database");')
DB_USER=$(php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("database.connections.mysql.username");')
DB_HOST=$(php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("database.connections.mysql.host");')

if [[ "$DB_NAME" != "mediguide_demo" ]]; then
  echo "FAIL: refusing unexpected database name"
  exit 1
fi

umask 077
openssl rand -base64 32 > "$PASS_PATH"
chmod 600 "$PASS_PATH"

# Password prompted by mysqldump via defaults or interactive; prefer ~/.my.cnf if present.
# Never echo DB password.
mysqldump --single-transaction --routines --triggers -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" > "$SQL_PATH"
openssl enc -aes-256-cbc -salt -pbkdf2 -in "$SQL_PATH" -out "$ENC_PATH" -pass file:"$PASS_PATH"
shred -u "$SQL_PATH" 2>/dev/null || rm -f "$SQL_PATH"
chmod 600 "$ENC_PATH"

echo "DB=$DB_NAME"
echo "ENC=$ENC_PATH"
echo "PASS_FILE=$PASS_PATH"
echo "GIT=$(git -C "$APP" rev-parse --short HEAD)"
echo "BACKUP_DONE=yes"
echo "Keep PASS_FILE offline; do not commit."
