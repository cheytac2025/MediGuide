#!/usr/bin/env bash
# Phase 4J — rollback account-invitation deploy to a prior Git SHA.
# Does not restore DB unless RESTORE_SQL_ENC is explicitly provided.
# Does not enable OTP flags.
set -euo pipefail

APP="${APP_ROOT:-/var/www/mediguide}"
APPROVAL_REQUIRED='APPROVED rollback MediGuide account invitation auth'
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/secure_config_cache.sh
source "$SCRIPT_DIR/lib/secure_config_cache.sh"

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  exit 2
fi

if [[ -z "${PREVIOUS_SHA:-}" ]]; then
  echo "Refusing: set PREVIOUS_SHA=<git sha before invitation deploy>"
  exit 2
fi

cd "$APP"
echo "CURRENT=$(git rev-parse HEAD)"
git fetch origin
git checkout --detach "$PREVIOUS_SHA"
echo "ROLLED_TO=$(git rev-parse HEAD)"

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
'

if [[ "${ROLLBACK_MIGRATION:-}" == "yes" ]]; then
  echo "Rolling back account_invitations migration (destructive to invitation rows)."
  php artisan migrate:rollback --force --path=database/migrations/2026_11_20_220000_add_pending_activation_and_account_invitations.php
fi

mediguide_secure_config_cache "$APP" || true
sudo systemctl reload php8.4-fpm

echo "ROLLBACK_DONE=yes"
echo "If schema/data inconsistency remains, restore from CONFIRM_BACKUP_ENC offline with DBA approval."
