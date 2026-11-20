#!/usr/bin/env bash
# Phase 4J Stage E — roll back patient verification flags / cutoffs.
set -euo pipefail

APP="${APP_ROOT:-/var/www/mediguide}"
APPROVAL_REQUIRED='APPROVED rollback MediGuide patient email verification'
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/secure_config_cache.sh
source "$SCRIPT_DIR/lib/secure_config_cache.sh"

cd "$APP"

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  exit 2
fi

BACKUP_DIR="${BACKUP_DIR:-/home/mediguide/secure-backups}"
STAMP=$(date -u +%Y%m%d%H%M%S)
SAFE="$BACKUP_DIR/dotenv_pre_patient_verification_rollback_${STAMP}.env"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
( umask 077; cp -a .env "$SAFE"; chmod 600 "$SAFE" )
echo "SAFETY_COPY=$SAFE"

if [[ -n "${RESTORE_BACKUP:-}" ]]; then
  [[ -f "$RESTORE_BACKUP" ]] || { echo "FAIL: RESTORE_BACKUP missing"; exit 1; }
  cp -a "$RESTORE_BACKUP" .env
  chmod 640 .env
  echo "RESTORED_FROM=$RESTORE_BACKUP"
else
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
$env = $set($env, 'AUTH_REQUIRE_VERIFIED_PATIENT', 'false');
$env = $set($env, 'AUTH_VERIFICATION_NEW_ACCOUNT_AFTER', '');
$env = $set($env, 'AUTH_VERIFICATION_GRACE_UNTIL', '');
$env = $set($env, 'AUTH_ENFORCE_EMAIL_VERIFICATION', 'false');
$env = $set($env, 'AUTH_EMAIL_OTP_ENABLED', 'false');
$env = $set($env, 'AUTH_REQUIRE_TOTP_PRIVILEGED', 'false');
file_put_contents($path, $env);
chmod($path, 0640);
echo "SOFT_ROLLBACK=yes\n";
PHP
fi

mediguide_secure_config_cache "$APP"

php <<'PHP'
<?php
require '/var/www/mediguide/vendor/autoload.php';
$app = require '/var/www/mediguide/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'AUTH_REQUIRE='.(config('auth_security.require_verified_patient') ? 'true' : 'false').PHP_EOL;
echo 'AUTH_ENFORCE='.(config('auth_security.enforce_email_verification') ? 'true' : 'false').PHP_EOL;
echo 'AUTH_OTP='.(config('auth_security.email_otp_enabled') ? 'true' : 'false').PHP_EOL;
echo 'AUTH_TOTP='.(config('auth_security.require_totp_privileged') ? 'true' : 'false').PHP_EOL;
PHP

curl -sk -o /dev/null -w 'HOME=%{http_code}\n' -H 'Host: qmhchospital.com' https://127.0.0.1/
curl -sk -o /dev/null -w 'LOGIN=%{http_code}\n' -H 'Host: qmhchospital.com' https://127.0.0.1/login
echo "PATIENT_VERIFICATION_ROLLBACK_DONE=yes"
