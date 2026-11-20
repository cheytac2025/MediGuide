#!/usr/bin/env bash
# Phase 4J — interactive Gmail SMTP configuration for MediGuide VPS.
# Never prints App Password. Preserves APP_KEY / Anthropic / integrity / audit keys.
# Does NOT enable registration verification or email OTP enforcement.
set -euo pipefail

APP="${APP_ROOT:-/var/www/mediguide}"
ENV_FILE="$APP/.env"
BACKUP_DIR="${BACKUP_DIR:-/home/mediguide/secure-backups}"
APPROVAL_REQUIRED='APPROVED configure MediGuide Gmail SMTP'
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/secure_config_cache.sh
source "$SCRIPT_DIR/lib/secure_config_cache.sh"

cd "$APP"

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  exit 2
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "FAIL: missing $ENV_FILE"
  exit 1
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
STAMP=$(date -u +%Y%m%d%H%M%S)
BACKUP="$BACKUP_DIR/dotenv_pre_gmail_smtp_${STAMP}.env"
# Isolate umask 077 to the backup copy only. A leaked 077 makes config:cache create mode 600.
( umask 077; cp -a "$ENV_FILE" "$BACKUP"; chmod 600 "$BACKUP" )
echo "BACKUP=$BACKUP"

php -r '
$env=file_get_contents($argv[1]);
foreach (["APP_KEY","ANTHROPIC_API_KEY","MEDIGUIDE_INTEGRITY_KEY","MEDIGUIDE_AUDIT_KEY"] as $k) {
  echo $k."=".(preg_match("/^".preg_quote($k,"/")."=.+/m",$env)?"present":"MISSING").PHP_EOL;
}
' "$ENV_FILE"

echo "Enter dedicated Gmail address (MAIL_USERNAME / MAIL_FROM_ADDRESS):"
read -r GMAIL_ADDRESS
GMAIL_ADDRESS=$(printf '%s' "$GMAIL_ADDRESS" | tr -d '\r' | xargs)
if [[ ! "$GMAIL_ADDRESS" =~ ^[^@[:space:]]+@gmail\.com$ ]]; then
  echo "FAIL: expected a gmail.com address"
  exit 1
fi

echo "Enter Google App Password (input hidden; never logged):"
read -rs APP_PASSWORD
echo
APP_PASSWORD=$(printf '%s' "$APP_PASSWORD" | tr -d ' \t\r\n')
if [[ ${#APP_PASSWORD} -lt 12 ]]; then
  echo "FAIL: App Password looks too short"
  unset APP_PASSWORD
  exit 1
fi

export MG_MAIL_USER="$GMAIL_ADDRESS"
export MG_MAIL_PASS="$APP_PASSWORD"
unset APP_PASSWORD

php <<'PHP'
<?php
$path = '/var/www/mediguide/.env';
$email = getenv('MG_MAIL_USER') ?: '';
$pass = getenv('MG_MAIL_PASS') ?: '';
if ($email === '' || $pass === '') {
    fwrite(STDERR, "FAIL: missing credentials in environment\n");
    exit(1);
}
$env = file_get_contents($path);
if ($env === false) {
    fwrite(STDERR, "FAIL: cannot read .env\n");
    exit(1);
}

$set = function (string $env, string $key, string $value): string {
    $line = $key.'='.$value;
    $pattern = '/^'.preg_quote($key, '/').'=.*/m';
    if (preg_match($pattern, $env)) {
        return (string) preg_replace($pattern, $line, $env, 1);
    }

    return rtrim($env)."\n".$line."\n";
};

$env = $set($env, 'MAIL_MAILER', 'smtp');
$env = $set($env, 'MAIL_SCHEME', 'null');
$env = $set($env, 'MAIL_HOST', 'smtp.gmail.com');
$env = $set($env, 'MAIL_PORT', '587');
$env = $set($env, 'MAIL_USERNAME', $email);
$env = $set($env, 'MAIL_PASSWORD', $pass);
$env = $set($env, 'MAIL_FROM_ADDRESS', $email);
$env = $set($env, 'MAIL_FROM_NAME', 'MediGuide');
$env = $set($env, 'AUTH_REQUIRE_VERIFIED_PATIENT', 'false');
$env = $set($env, 'AUTH_ENFORCE_EMAIL_VERIFICATION', 'false');
$env = $set($env, 'AUTH_EMAIL_OTP_ENABLED', 'false');
$env = $set($env, 'AUTH_REQUIRE_TOTP_PRIVILEGED', 'false');

if (file_put_contents($path, $env) === false) {
    fwrite(STDERR, "FAIL: cannot write .env\n");
    exit(1);
}

echo "ENV_UPDATED=yes\n";
PHP

unset MG_MAIL_PASS MG_MAIL_USER
chmod 640 "$ENV_FILE" 2>/dev/null || chmod 600 "$ENV_FILE"

php <<'PHP'
<?php
$env = file_get_contents('/var/www/mediguide/.env');
$get = function (string $key) use ($env): ?string {
    if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m)) {
        return null;
    }

    return trim($m[1], " \t\"'");
};
foreach (['MAIL_MAILER', 'MAIL_SCHEME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_FROM_NAME'] as $k) {
    echo $k.'='.($get($k) ?? 'missing').PHP_EOL;
}
foreach (['MAIL_USERNAME', 'MAIL_FROM_ADDRESS'] as $k) {
    $v = $get($k);
    if ($v === null) {
        echo $k.'=missing'.PHP_EOL;
        continue;
    }
    $domain = str_contains($v, '@') ? substr((string) strrchr($v, '@'), 1) : 'n/a';
    echo $k.'=set domain='.$domain.PHP_EOL;
}
echo 'MAIL_PASSWORD='.($get('MAIL_PASSWORD') ? 'set' : 'missing').PHP_EOL;
foreach (['APP_KEY', 'ANTHROPIC_API_KEY', 'MEDIGUIDE_INTEGRITY_KEY', 'MEDIGUIDE_AUDIT_KEY'] as $k) {
    echo $k.'='.($get($k) ? 'present' : 'MISSING').PHP_EOL;
}
foreach (['AUTH_REQUIRE_VERIFIED_PATIENT', 'AUTH_ENFORCE_EMAIL_VERIFICATION', 'AUTH_EMAIL_OTP_ENABLED', 'AUTH_REQUIRE_TOTP_PRIVILEGED'] as $k) {
    echo $k.'='.($get($k) ?? 'missing').PHP_EOL;
}
PHP

if ! mediguide_secure_config_cache "$APP"; then
  echo "WARN: secure config cache failed; site remains on .env until config_cache_rebuild.sh succeeds"
fi

echo "GMAIL_SMTP_CONFIGURED=yes"
echo "ROLLBACK_BACKUP=$BACKUP"
echo "NEXT_DRY_RUN=php artisan mediguide:mail-pilot RECIPIENT --approval='APPROVED send MediGuide mail pilot'"
echo "NEXT_SEND_REQUIRES_SEPARATE_APPROVAL=add --execute"
