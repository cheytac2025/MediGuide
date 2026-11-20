#!/usr/bin/env bash
# Phase 4J — rebuild Laravel config cache with least-privilege www-data readability.
# Does NOT add mediguide to www-data. Does NOT touch auth flags, mail, or clinical data.
set -euo pipefail

APP="${APP_ROOT:-/var/www/mediguide}"
APPROVAL_REQUIRED='APPROVED rebuild MediGuide config cache'
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/secure_config_cache.sh
source "$SCRIPT_DIR/lib/secure_config_cache.sh"

cd "$APP"

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  exit 2
fi

echo "HOST=$(hostname)"
echo "GIT=$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
echo "FPM_ACTIVE=$(systemctl is-active php8.4-fpm 2>/dev/null || echo unknown)"
echo "QUEUE_ACTIVE=$(systemctl is-active mediguide-queue 2>/dev/null || echo unknown)"
echo "DEPLOY_GROUPS=$(id -nG)"
echo "UMASK_BEFORE=$(umask)"

echo '===BEFORE==='
ls -la bootstrap/cache/ || true
stat -c 'cache_dir mode=%a owner=%U group=%G' bootstrap/cache
stat -c 'env owner=%U group=%G mode=%a' .env

# Detect setgid; do not expand mediguide groups.
python3 - <<'PY' 2>/dev/null || php -r '$m=fileperms("bootstrap/cache"); printf("SETGID=%s\n", ($m & 02000)?"yes":"no");'
import os, stat
p = os.stat("bootstrap/cache")
print("SETGID=" + ("yes" if p.st_mode & stat.S_ISGID else "no"))
PY

echo '===REBUILD==='
set +e
mediguide_secure_config_cache "$APP"
rc=$?
set -e

echo "UMASK_AFTER=$(umask)"

if [[ $rc -ne 0 ]]; then
  echo "FAIL: secure config cache rebuild returned $rc"
  cat <<'EOF'
SAFE_STATE: prior config.php restored when available, else config cleared (.env fallback).
IF_SETGID_MISSING_ONE_TIME_SUDO_ONLY:
  sudo chgrp www-data /var/www/mediguide/bootstrap/cache
  sudo chmod 2750 /var/www/mediguide/bootstrap/cache
  # Do NOT usermod -aG www-data mediguide
THEN_RETRY:
  cd /var/www/mediguide
  APPROVAL_PHRASE='APPROVED rebuild MediGuide config cache' ./scripts/phase4j/config_cache_rebuild.sh
EMERGENCY_EXPLICIT_CHOWN_PATH:
  cd /var/www/mediguide && php artisan config:cache
  sudo chgrp www-data bootstrap/cache/config.php bootstrap/cache/packages.php bootstrap/cache/services.php
  sudo chmod 640 bootstrap/cache/config.php bootstrap/cache/packages.php bootstrap/cache/services.php
EOF
  exit "$rc"
fi

echo '===VERIFY_PERMS==='
mediguide_verify_cache_readable_by_www_data "$APP"

echo '===ENV_STILL_PROTECTED==='
stat -c 'env owner=%U group=%G mode=%a' .env
mode=$(stat -c '%a' .env)
group=$(stat -c '%G' .env)
if [[ "$mode" != "640" || "$group" != "www-data" ]]; then
  echo "WARN: unexpected .env posture (expected 640 mediguide:www-data)"
else
  echo "ENV_OK=640 mediguide:www-data (www-data read-only, no write)"
fi

echo '===CONFIG_SMOKE_NO_SECRETS==='
php <<'PHP'
<?php
require '/var/www/mediguide/vendor/autoload.php';
$app = require '/var/www/mediguide/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'MAIL='.config('mail.default').PHP_EOL;
echo 'MAIL_HOST='.config('mail.mailers.smtp.host').PHP_EOL;
echo 'FROM_DOMAIN='.(str_contains((string) config('mail.from.address'), '@') ? substr((string) strrchr((string) config('mail.from.address'), '@'), 1) : 'n/a').PHP_EOL;
echo 'DB='.config('database.connections.mysql.database').PHP_EOL;
echo 'APP_DEBUG='.(config('app.debug') ? 'true' : 'false').PHP_EOL;
echo 'ANTHROPIC='.(config('services.anthropic.key') ? 'present' : 'missing').PHP_EOL;
echo 'APP_KEY='.(config('app.key') ? 'present' : 'MISSING').PHP_EOL;
echo 'AUTH_REQUIRE='.(config('auth_security.require_verified_patient') ? 'true' : 'false').PHP_EOL;
echo 'AUTH_ENFORCE='.(config('auth_security.enforce_email_verification') ? 'true' : 'false').PHP_EOL;
echo 'AUTH_OTP='.(config('auth_security.email_otp_enabled') ? 'true' : 'false').PHP_EOL;
echo 'AUTH_TOTP='.(config('auth_security.require_totp_privileged') ? 'true' : 'false').PHP_EOL;
echo 'USING_CACHE='.(file_exists('/var/www/mediguide/bootstrap/cache/config.php') ? 'yes' : 'no').PHP_EOL;
PHP

echo '===HTTP==='
curl -sk -o /dev/null -w 'HOME=%{http_code}\n' -H 'Host: qmhchospital.com' https://127.0.0.1/
curl -sk -o /dev/null -w 'LOGIN=%{http_code}\n' -H 'Host: qmhchospital.com' https://127.0.0.1/login

echo 'CONFIG_CACHE_REBUILD_DONE=yes'
echo 'RECOVERY_IF_500=cd /var/www/mediguide && php artisan config:clear'
