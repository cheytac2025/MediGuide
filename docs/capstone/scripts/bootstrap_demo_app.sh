#!/usr/bin/env bash
# Phase 1: create isolated demo app + DB (no public DNS/TLS required yet).
# Does not modify production /var/www/mediguide or mediguide_demo.
set -euo pipefail

APPROVAL_REQUIRED='APPROVED deploy MediGuide isolated online capstone demonstration'
EXPECTED_SHA="${EXPECTED_SHA:?set EXPECTED_SHA to approved full commit}"
APP_ROOT=/var/www/mediguide-demo
DB_NAME=mediguide_capstone_full_demo
DB_USER=mediguide_demo_app
CRED_DIR=/home/mediguide/secure-backups/demo
STAMP=$(date -u +%Y%m%d%H%M%S)
REPO_URL="${REPO_URL:-https://github.com/cheytac2025/MediGuide.git}"

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  exit 2
fi
[[ "$(hostname)" == "srv2179431" ]] || { echo FAIL_HOST; exit 1; }
[[ "$(whoami)" == "mediguide" ]] || { echo FAIL_USER; exit 1; }

echo "=== PROD BASELINE ==="
php <<'PHP'
<?php
require '/var/www/mediguide/vendor/autoload.php';
$a = require '/var/www/mediguide/bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'PROD_DB='.config('database.connections.mysql.database').PHP_EOL;
echo 'PROD_PROVISIONAL='.var_export(config('hospital.provisional_local_booking'), true).PHP_EOL;
echo 'PROD_APPT='.Illuminate\Support\Facades\DB::table('appointments')->count().PHP_EOL;
echo 'PROD_PAT='.Illuminate\Support\Facades\DB::table('patients')->count().PHP_EOL;
if (config('database.connections.mysql.database') !== 'mediguide_demo') { fwrite(STDERR, "unexpected prod db\n"); exit(1); }
if (config('hospital.provisional_local_booking')) { fwrite(STDERR, "prod provisional on\n"); exit(1); }
PHP

mkdir -p "$CRED_DIR"
chmod 700 /home/mediguide/secure-backups "$CRED_DIR"
DB_PASS=$(openssl rand -base64 24 | tr -d '/+=' | head -c 28)
BASIC_PASS=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)
PAT_PASS=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)
STAFF_PASS=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)
DOC_PASS=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)
ADM_PASS=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)
INT_KEY="base64:$(openssl rand -base64 32)"
AUD_KEY="base64:$(openssl rand -base64 32)"
CRED_FILE="$CRED_DIR/demo_credentials_${STAMP}.txt"
umask 077
cat > "$CRED_FILE" <<EOF
# MediGuide isolated demo credentials — ${STAMP} UTC
DEMO_URL=https://demo.qmhchospital.com
BASIC_AUTH_USER=defense
BASIC_AUTH_PASSWORD=${BASIC_PASS}
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}
PATIENT_EMAIL=patient.demo@mediguide.test
PATIENT_PASSWORD=${PAT_PASS}
STAFF_EMAIL=staff.demo@mediguide.test
STAFF_PASSWORD=${STAFF_PASS}
DOCTOR_EMAIL=doctor.demo@mediguide.test
DOCTOR_PASSWORD=${DOC_PASS}
ADMIN_EMAIL=admin.demo@mediguide.test
ADMIN_PASSWORD=${ADM_PASS}
EXPECTED_SHA=${EXPECTED_SHA}
EOF
chmod 600 "$CRED_FILE"
echo "CRED_FILE=$CRED_FILE"

echo "=== DEPENDENCY CHECK (no APT / no PHP reinstall) ==="
php -v | head -n1
command -v composer >/dev/null || { echo "FAIL composer missing"; exit 1; }
command -v mysql >/dev/null || { echo "FAIL mysql client missing"; exit 1; }
command -v git >/dev/null || { echo "FAIL git missing"; exit 1; }
command -v openssl >/dev/null || { echo "FAIL openssl missing"; exit 1; }
command -v python3 >/dev/null || { echo "FAIL python3 missing"; exit 1; }
# htpasswd/apache2-utils intentionally not required — publish script uses openssl apr1 hashes.
echo "APT_SKIPPED=yes REASON=php_and_tools_already_present"

echo "=== CLEAN incomplete prior credentials (keep successful BOOTSTRAP_DONE markers only) ==="
# Remove orphaned credential files from failed runs that never finished bootstrap.
find "$CRED_DIR" -maxdepth 1 -type f -name 'demo_credentials_*.txt' ! -name '*.ok' -mtime -30 2>/dev/null | while read -r f; do
  if [[ ! -f "${f}.ok" ]]; then
    echo "REMOVE_INCOMPLETE=$(basename "$f")"
    shred -u "$f" 2>/dev/null || rm -f "$f"
  fi
done
find "$CRED_DIR" -maxdepth 1 -type f -name 'basic_pass_*.tmp' -delete 2>/dev/null || true

echo "=== SUDO bootstrap (filesystem + MySQL only; no apt) ==="
sudo mkdir -p "$APP_ROOT" /var/lib/mediguide-demo/audit
sudo chown mediguide:www-data "$APP_ROOT" /var/lib/mediguide-demo /var/lib/mediguide-demo/audit
sudo chmod 750 "$APP_ROOT"

sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
GRANTS=$(sudo mysql -Nse "SHOW GRANTS FOR '${DB_USER}'@'127.0.0.1';")
echo "$GRANTS"
echo "$GRANTS" | grep -qi 'mediguide_demo`' && { echo "FAIL prod grant"; exit 1; } || echo "GRANT_SCOPE=demo_only"

if [[ ! -d "$APP_ROOT/.git" ]]; then
  git clone "$REPO_URL" "$APP_ROOT"
fi
cd "$APP_ROOT"
git fetch origin main
git checkout --force "$EXPECTED_SHA"
AFTER=$(git rev-parse HEAD)
echo "DEMO_GIT=$AFTER"
[[ "$AFTER" == "$EXPECTED_SHA" ]] || { echo "FAIL sha $AFTER != $EXPECTED_SHA"; exit 1; }

composer install --no-dev --optimize-autoloader --no-interaction
if [[ ! -f .env ]]; then
  cp .env.example .env
fi
# Generate APP_KEY only when empty (safe re-runs).
if ! grep -qE '^APP_KEY=base64:' .env; then
  php artisan key:generate --force >/dev/null
  echo "APP_KEY=generated"
else
  echo "APP_KEY=preserved"
fi

python3 - "$DB_PASS" "$INT_KEY" "$AUD_KEY" "$PAT_PASS" "$STAFF_PASS" "$DOC_PASS" "$ADM_PASS" <<'PY'
import sys
from pathlib import Path
db_pass, int_key, aud_key, pat, staff, doc, adm = sys.argv[1:8]
path = Path('.env')
lines = path.read_text(encoding='utf-8').replace('\r\n','\n').replace('\r','\n').split('\n')

def current(lines, key):
    for line in lines:
        if line.startswith(key+'='):
            return line.split('=',1)[1]
    return ''

def upsert(lines, key, value, *, only_if_empty=False):
    existing = current(lines, key)
    if only_if_empty and existing not in ('', 'null', '""'):
        return lines
    out=[]; found=False
    for line in lines:
        if line.startswith(key+'='):
            if found: continue
            out.append(f'{key}={value}'); found=True
        else:
            out.append(line)
    if not found: out.append(f'{key}={value}')
    return out

vals = {
 'APP_NAME': '"MediGuide Demo"',
 'APP_ENV': 'production',
 'APP_DEBUG': 'false',
 'APP_URL': 'https://demo.qmhchospital.com',
 'APP_TIMEZONE': 'Asia/Manila',
 'DB_CONNECTION': 'mysql',
 'DB_HOST': '127.0.0.1',
 'DB_PORT': '3306',
 'DB_DATABASE': 'mediguide_capstone_full_demo',
 'DB_USERNAME': 'mediguide_demo_app',
 'DB_PASSWORD': f'"{db_pass}"',
 'SESSION_DRIVER': 'database',
 'SESSION_COOKIE': 'mediguide_demo_session',
 'SESSION_DOMAIN': 'demo.qmhchospital.com',
 'CACHE_STORE': 'database',
 'CACHE_PREFIX': 'mgdemo_',
 'QUEUE_CONNECTION': 'database',
 'MAIL_MAILER': 'log',
 'HOSPITAL_DATA_SOURCE': 'imported',
 'HOSPITAL_PROVISIONAL_LOCAL_BOOKING': 'true',
 'MEDIGUIDE_DEMO_INSTANCE': 'true',
 'MEDIGUIDE_DEMO_DATABASE': 'mediguide_capstone_full_demo',
 'MEDIGUIDE_INTEGRITY_KEY_VERSION': '1',
 'MEDIGUIDE_AUDIT_KEY_VERSION': '1',
 'MEDIGUIDE_AUDIT_CHECKPOINT_PATH': '/var/lib/mediguide-demo/audit/checkpoint.jsonl',
 'AUTH_REQUIRE_VERIFIED_PATIENT': 'false',
 'AUTH_ENFORCE_EMAIL_VERIFICATION': 'false',
 'AUTH_EMAIL_OTP_ENABLED': 'false',
 'AUTH_REQUIRE_EMAIL_OTP_ALL_ROLES': 'false',
 'ANTHROPIC_API_KEY': 'invalid-mocked-demo-key',
 'MEDIGUIDE_DEMO_PATIENT_PASSWORD': pat,
 'MEDIGUIDE_DEMO_STAFF_PASSWORD': staff,
 'MEDIGUIDE_DEMO_DOCTOR_PASSWORD': doc,
 'MEDIGUIDE_DEMO_ADMIN_PASSWORD': adm,
}
for k,v in vals.items():
    lines = upsert(lines, k, v)
# Preserve existing integrity/audit keys on re-run.
lines = upsert(lines, 'MEDIGUIDE_INTEGRITY_KEY', int_key, only_if_empty=True)
lines = upsert(lines, 'MEDIGUIDE_AUDIT_KEY', aud_key, only_if_empty=True)
path.write_text('\n'.join(lines)+'\n', encoding='utf-8')
print('ENV_WRITTEN=yes')
PY
chmod 600 .env

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
sudo chmod 2750 bootstrap/cache

php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\RoleSeeder --force
php artisan mediguide:import-hospital-schedules --execute --bookable-slot-minutes=30 --no-interaction
php artisan mediguide:ensure-demo-booking-coverage --confirm-local-demonstration --confirm-database="$DB_NAME"
php artisan db:seed --class=Database\\Seeders\\CapstoneDemoAccountSeeder --force

# shellcheck source=/dev/null
source "$APP_ROOT/scripts/phase4j/lib/secure_config_cache.sh"
mediguide_secure_config_cache "$APP_ROOT"
mediguide_verify_cache_readable_by_www_data "$APP_ROOT"
stat -c 'config=%a %U:%G' bootstrap/cache/config.php

php <<'PHP'
<?php
require '/var/www/mediguide-demo/vendor/autoload.php';
$app = require '/var/www/mediguide-demo/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Doctor;
use App\Services\AppointmentBookingService;
use App\Support\Demo\DemoBookingEnvironment;
echo 'DEMO_DB='.config('database.connections.mysql.database').PHP_EOL;
echo 'AUTHORIZED='.var_export(DemoBookingEnvironment::isAuthorizedOnlineDemoInstance(), true).PHP_EOL;
echo 'PROV_ALLOWED='.var_export(DemoBookingEnvironment::provisionalBookingAllowed(), true).PHP_EOL;
$booking = app(AppointmentBookingService::class);
$n=0;$b=0;
foreach (Doctor::query()->where('status','active')->orderBy('id')->get() as $d) { $n++; if ($booking->nextAvailableSlot($d)) $b++; }
echo "ACTIVE=$n BOOKABLE=$b".PHP_EOL;
if ($n !== 16 || $b !== 16) { exit(1); }
if (config('database.connections.mysql.database') === 'mediguide_demo') { exit(1); }
PHP

# Save basic pass for publish phase
echo "$BASIC_PASS" > "$CRED_DIR/basic_pass_${STAMP}.tmp"
chmod 600 "$CRED_DIR/basic_pass_${STAMP}.tmp"
echo "BASIC_PASS_TMP=$CRED_DIR/basic_pass_${STAMP}.tmp"

php <<'PHP'
<?php
require '/var/www/mediguide/vendor/autoload.php';
$a = require '/var/www/mediguide/bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'PROD_AFTER_APPT='.Illuminate\Support\Facades\DB::table('appointments')->count().PHP_EOL;
echo 'PROD_AFTER_PAT='.Illuminate\Support\Facades\DB::table('patients')->count().PHP_EOL;
echo 'PROD_AFTER_PROV='.var_export(config('hospital.provisional_local_booking'), true).PHP_EOL;
PHP

touch "${CRED_FILE}.ok"
chmod 600 "${CRED_FILE}.ok"
echo "BOOTSTRAP_DONE=yes"
echo "CRED_FILE_OK=${CRED_FILE}.ok"
echo "NEXT=Create DNS A record demo.qmhchospital.com -> $(curl -sS --max-time 10 https://api.ipify.org) then run publish_demo_nginx.sh"
