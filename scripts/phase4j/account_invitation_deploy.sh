#!/usr/bin/env bash
# Phase 4J — controlled deploy of admin invitation + all-role OTP foundation.
# Keeps AUTH_REQUIRE_EMAIL_OTP_ALL_ROLES=false and does not send pilot email.
set -euo pipefail

APP="${APP_ROOT:-/var/www/mediguide}"
BACKUP_DIR="${BACKUP_DIR:-/home/mediguide/secure-backups/phase4j}"
APPROVAL_REQUIRED='APPROVED deploy MediGuide account invitation auth'
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/secure_config_cache.sh
source "$SCRIPT_DIR/lib/secure_config_cache.sh"

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  echo "Also require a prior encrypted DB backup + audit checkpoint backup."
  exit 2
fi

cd "$APP"

echo "HOST=$(hostname)"
echo "START_GIT=$(git rev-parse HEAD)"
echo "START_BRANCH=$(git rev-parse --abbrev-ref HEAD)"

php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=config("database.connections.mysql.database");
if ($db !== "mediguide_demo") { fwrite(STDERR, "FAIL unexpected DB=$db\n"); exit(1); }
if (config("auth_security.require_email_otp_all_roles")) { fwrite(STDERR, "FAIL all-role OTP already enabled\n"); exit(1); }
if (config("auth_security.require_totp_privileged")) { fwrite(STDERR, "FAIL privileged TOTP already enabled\n"); exit(1); }
echo "DB_OK=mediguide_demo\n";
echo "OTP_ALL_ROLES=false\n";
'

# Expect caller already created ENC dump via prod_db_backup_prepare.sh
if [[ -z "${CONFIRM_BACKUP_ENC:-}" || ! -f "${CONFIRM_BACKUP_ENC}" ]]; then
  echo "Refusing: set CONFIRM_BACKUP_ENC=/path/to/mediguide_demo_*.sql.enc from a fresh backup"
  exit 2
fi
echo "BACKUP_ENC=$CONFIRM_BACKUP_ENC"

# Pull reviewed commit (main or explicit REF). Does not merge locally beyond fetch/checkout.
REF="${DEPLOY_REF:-origin/main}"
git fetch origin
git checkout --detach "$REF"
echo "DEPLOY_GIT=$(git rev-parse HEAD)"

# Additive migration only — path-scoped.
php artisan migrate --force --path=database/migrations/2026_11_20_220000_add_pending_activation_and_account_invitations.php

# Ensure dangerous flags stay false in .env (idempotent, does not print secrets).
php -r '
$path=".env";
$env=file_get_contents($path);
$set=function(string $env, string $key, string $value): string {
  $line=$key."=".$value;
  if (preg_match("/^".preg_quote($key,"/")."=.*/m", $env)) {
    return preg_replace("/^".preg_quote($key,"/")."=.*/m", $line, $env, 1);
  }
  return rtrim($env).PHP_EOL.$line.PHP_EOL;
};
$env=$set($env, "AUTH_REQUIRE_EMAIL_OTP_ALL_ROLES", "false");
$env=$set($env, "AUTH_REQUIRE_TOTP_PRIVILEGED", "false");
file_put_contents($path, $env);
echo "FLAGS_PINNED=yes\n";
'

if ! mediguide_secure_config_cache "$APP"; then
  echo "FAIL: secure config cache rebuild"
  exit 1
fi

sudo systemctl reload php8.4-fpm
sudo systemctl is-active --quiet mediguide-queue || sudo systemctl restart mediguide-queue

php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'POST_DB='.config('database.connections.mysql.database').PHP_EOL;
echo 'POST_OTP_ALL_ROLES='.(config('auth_security.require_email_otp_all_roles') ? 'true' : 'false').PHP_EOL;
echo 'POST_TOTP_PRIV='.(config('auth_security.require_totp_privileged') ? 'true' : 'false').PHP_EOL;
echo 'POST_TABLE='.(Illuminate\Support\Facades\Schema::hasTable('account_invitations') ? 'yes' : 'no').PHP_EOL;
PHP

echo "curl smoke (HTTP codes only):"
curl -sS -o /dev/null -w 'HOME=%{http_code}\n' https://127.0.0.1/ -H 'Host: mediguide.local' --resolve mediguide.local:443:127.0.0.1 || curl -sS -o /dev/null -w 'HOME=%{http_code}\n' http://127.0.0.1/ || true
curl -sS -o /dev/null -w 'LOGIN=%{http_code}\n' -L http://127.0.0.1/login || true

echo "DEPLOY_DONE=yes"
echo "NEXT: run integrity/audit verification; do NOT send production invitations without separate approval."
echo "ROLLBACK: git checkout --detach PREVIOUS_SHA; reload php-fpm; optional migrate:rollback --path=... only if no invitation rows consumed."
