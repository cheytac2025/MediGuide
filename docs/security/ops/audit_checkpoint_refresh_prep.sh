#!/usr/bin/env bash
# PREPARE / EXECUTE audit checkpoint refresh for FIND-007.
# Default mode=prep (read-only). Execute requires APPROVAL_PHRASE.
set -euo pipefail

APP=/var/www/mediguide
BACKUP_DIR=/home/mediguide/secure-backups/phase4j
MODE="${1:-prep}"
APPROVAL_REQUIRED='APPROVED refresh MediGuide audit checkpoint'
STAMP=$(date -u +%Y%m%d%H%M%S)

cd "$APP"
echo "HOST=$(hostname)"
echo "MODE=$MODE"
[[ "$(hostname)" == "srv2179431" ]] || { echo "FAIL host"; exit 1; }

echo "=== AUDIT_VERIFY ==="
php artisan mediguide:audit-verify

CP_PATH=$(php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (string) config("audit.checkpoint_path");')
echo "CHECKPOINT_PATH=$CP_PATH"
[[ -n "$CP_PATH" && -f "$CP_PATH" ]] || { echo "FAIL missing checkpoint file"; exit 1; }

echo "=== PRE_BACKUP_EXISTING_CHECKPOINT ==="
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR" /home/mediguide/secure-backups
CP_BAK="$BACKUP_DIR/checkpoint_pre_refresh_${STAMP}.jsonl"
( umask 077; cp -a "$CP_PATH" "$CP_BAK"; chmod 600 "$CP_BAK" )
CP_PASS="$BACKUP_DIR/.checkpoint_pass_pre_refresh_${STAMP}"
CP_ENC="$BACKUP_DIR/checkpoint_pre_refresh_${STAMP}.jsonl.enc"
( umask 077; openssl rand -base64 32 > "$CP_PASS"; chmod 600 "$CP_PASS" )
openssl enc -aes-256-cbc -pbkdf2 -salt -in "$CP_PATH" -out "$CP_ENC" -pass file:"$CP_PASS"
chmod 600 "$CP_ENC"
echo "CHECKPOINT_BAK=$CP_BAK"
echo "CHECKPOINT_ENC=$CP_ENC"
echo "CHECKPOINT_PASS_FILE=$CP_PASS"
echo "NOTE=store pass file separately from encrypted blob if offline backup is copied"

if [[ "$MODE" == "prep" ]]; then
  echo "PREP_DONE=yes"
  echo "NO_CHECKPOINT_REFRESH_EXECUTED=yes"
  echo "NEXT=run with mode=execute after approval"
  exit 0
fi

if [[ "$MODE" != "execute" ]]; then
  echo "Usage: $0 prep|execute"
  exit 2
fi

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  exit 2
fi

echo "=== REFRESH_CHECKPOINT ==="
# Does not regenerate audit keys or rewrite history — appends new checkpoint head.
# --execute is required; without it the command is a dry run.
php artisan mediguide:audit-checkpoint --execute
php artisan mediguide:audit-verify

POST_BAK="$BACKUP_DIR/checkpoint_post_refresh_${STAMP}.jsonl"
( umask 077; cp -a "$CP_PATH" "$POST_BAK"; chmod 600 "$POST_BAK" )
POST_PASS="$BACKUP_DIR/.checkpoint_pass_post_refresh_${STAMP}"
POST_ENC="$BACKUP_DIR/checkpoint_post_refresh_${STAMP}.jsonl.enc"
( umask 077; openssl rand -base64 32 > "$POST_PASS"; chmod 600 "$POST_PASS" )
openssl enc -aes-256-cbc -pbkdf2 -salt -in "$CP_PATH" -out "$POST_ENC" -pass file:"$POST_PASS"
chmod 600 "$POST_ENC"
echo "POST_CHECKPOINT_ENC=$POST_ENC"
echo "POST_CHECKPOINT_PASS_FILE=$POST_PASS"
echo "REFRESH_DONE=yes"
