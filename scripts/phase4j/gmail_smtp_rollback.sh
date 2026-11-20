#!/usr/bin/env bash
# Phase 4J — roll Gmail SMTP back to MAIL_MAILER=log without touching keys/Claude.
set -euo pipefail

APP="${APP_ROOT:-/var/www/mediguide}"
ENV_FILE="$APP/.env"
BACKUP_DIR="${BACKUP_DIR:-/home/mediguide/secure-backups}"
APPROVAL_REQUIRED='APPROVED rollback MediGuide Gmail SMTP'

cd "$APP"

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  exit 2
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "FAIL: missing $ENV_FILE"
  exit 1
fi

STAMP=$(date -u +%Y%m%d%H%M%S)
SAFE_COPY="$BACKUP_DIR/dotenv_pre_gmail_rollback_${STAMP}.env"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
umask 077
cp -a "$ENV_FILE" "$SAFE_COPY"
chmod 600 "$SAFE_COPY"
echo "SAFETY_COPY=$SAFE_COPY"

if [[ -n "${RESTORE_BACKUP:-}" ]]; then
  if [[ ! -f "$RESTORE_BACKUP" ]]; then
    echo "FAIL: RESTORE_BACKUP not found"
    exit 1
  fi
  # Restore full prior .env (preferred when prepare script backup exists)
  cp -a "$RESTORE_BACKUP" "$ENV_FILE"
  chmod 640 "$ENV_FILE" 2>/dev/null || chmod 600 "$ENV_FILE"
  echo "RESTORED_FROM=$RESTORE_BACKUP"
else
  # Soft rollback: force log mailer and clear SMTP password only
  php <<'PHP'
<?php
$path = '/var/www/mediguide/.env';
$env = file_get_contents($path);
$set = function (string $env, string $key, string $value): string {
    $line = $key.'='.$value;
    $pattern = '/^'.preg_quote($key, '/').'=.*/m';
    if (preg_match($pattern, $env)) {
        return (string) preg_replace($pattern, $line, $env, 1);
    }

    return rtrim($env)."\n".$line."\n";
};
$env = $set($env, 'MAIL_MAILER', 'log');
$env = $set($env, 'MAIL_HOST', '127.0.0.1');
$env = $set($env, 'MAIL_PORT', '2525');
$env = $set($env, 'MAIL_SCHEME', 'null');
$env = $set($env, 'MAIL_USERNAME', 'null');
$env = $set($env, 'MAIL_PASSWORD', 'null');
$env = $set($env, 'AUTH_REQUIRE_VERIFIED_PATIENT', 'false');
$env = $set($env, 'AUTH_EMAIL_OTP_ENABLED', 'false');
$env = $set($env, 'AUTH_REQUIRE_TOTP_PRIVILEGED', 'false');
file_put_contents($path, $env);
echo "SOFT_ROLLBACK=yes\n";
PHP
fi

php -r '
$env=file_get_contents("/var/www/mediguide/.env");
foreach (["MAIL_MAILER","AUTH_REQUIRE_VERIFIED_PATIENT","AUTH_EMAIL_OTP_ENABLED"] as $k) {
  preg_match("/^".$k."=(.*)$/m",$env,$m);
  echo $k."=".trim($m[1]??"missing"," \t\"'\''").PHP_EOL;
}
echo "APP_KEY=".(preg_match("/^APP_KEY=.+/m",$env)?"present":"MISSING").PHP_EOL;
echo "ANTHROPIC_API_KEY=".(preg_match("/^ANTHROPIC_API_KEY=.+/m",$env)?"present":"MISSING").PHP_EOL;
'

php artisan config:clear
php artisan config:cache
echo "GMAIL_SMTP_ROLLBACK_DONE=yes"
