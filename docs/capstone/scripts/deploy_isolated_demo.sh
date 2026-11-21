#!/usr/bin/env bash
# Controlled deployment of MediGuide isolated capstone demo.
# Requires: interactive sudo, DNS A record for demo.qmhchospital.com → this VPS.
# Does NOT modify /var/www/mediguide or mediguide_demo.
set -euo pipefail

APPROVAL_REQUIRED='APPROVED deploy MediGuide isolated online capstone demonstration'
EXPECTED_SHA="${EXPECTED_SHA:-}"
# Set EXPECTED_SHA to the approved full git SHA before running.
APP_ROOT=/var/www/mediguide-demo
DB_NAME=mediguide_capstone_full_demo
DB_USER=mediguide_demo_app
DEMO_HOST=demo.qmhchospital.com
CRED_DIR=/home/mediguide/secure-backups/demo
STAMP=$(date -u +%Y%m%d%H%M%S)
REPO_URL="${REPO_URL:-https://github.com/cheytac2025/MediGuide.git}"

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  exit 2
fi

[[ "$(hostname)" == "srv2179431" ]] || { echo "FAIL host"; exit 1; }
[[ "$(whoami)" == "mediguide" ]] || { echo "FAIL user"; exit 1; }

echo "=== PRECHECKS ==="
PROD_APPT=$(php -r 'require "/var/www/mediguide/vendor/autoload.php"; $a=require "/var/www/mediguide/bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo Illuminate\Support\Facades\DB::table("appointments")->count();')
PROD_PAT=$(php -r 'require "/var/www/mediguide/vendor/autoload.php"; $a=require "/var/www/mediguide/bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo Illuminate\Support\Facades\DB::table("patients")->count();')
PROD_PROV=$(php -r 'require "/var/www/mediguide/vendor/autoload.php"; $a=require "/var/www/mediguide/bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("hospital.provisional_local_booking") ? "true" : "false";')
echo "PROD_APPT=$PROD_APPT PROD_PAT=$PROD_PAT PROD_PROVISIONAL=$PROD_PROV"
[[ "$PROD_PROV" == "false" ]] || { echo "FAIL production provisional unexpectedly true"; exit 1; }

if ! getent hosts "$DEMO_HOST" >/dev/null 2>&1; then
  echo "FAIL DNS: $DEMO_HOST does not resolve. Create Hostinger A record → $(curl -sS --max-time 10 https://api.ipify.org) first."
  exit 1
fi
DEMO_IP=$(getent hosts "$DEMO_HOST" | awk '{print $1}' | head -n1)
PUBLIC_IP=$(curl -sS --max-time 10 https://api.ipify.org)
echo "DEMO_DNS=$DEMO_IP PUBLIC_IP=$PUBLIC_IP"
[[ "$DEMO_IP" == "$PUBLIC_IP" ]] || { echo "FAIL DNS points to $DEMO_IP not $PUBLIC_IP"; exit 1; }

mkdir -p "$CRED_DIR"
chmod 700 /home/mediguide/secure-backups "$CRED_DIR"

# Generate secrets (never echo values to stdout after write)
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
# Mode 600. Do not commit. Do not share outside the defense panel.
DEMO_URL=https://${DEMO_HOST}
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
EOF
chmod 600 "$CRED_FILE"
echo "CRED_FILE=$CRED_FILE"

echo "=== SUDO: filesystem + MySQL + packages ==="
sudo mkdir -p "$APP_ROOT" /var/lib/mediguide-demo/audit
sudo chown mediguide:www-data "$APP_ROOT" /var/lib/mediguide-demo /var/lib/mediguide-demo/audit
sudo chmod 750 "$APP_ROOT"
echo "APT_SKIPPED=yes REASON=use_openssl_apr1_for_htpasswd"

# MySQL DB + user (localhost + 127.0.0.1)
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
# Prove we did not grant on mediguide_demo
sudo mysql -Nse "SHOW GRANTS FOR '${DB_USER}'@'127.0.0.1';" | tee /tmp/demo_grants.txt
grep -q mediguide_demo /tmp/demo_grants.txt && { echo "FAIL demo user has prod DB grant"; exit 1; } || true

echo "=== APP TREE @ ${EXPECTED_SHA} ==="
if [[ ! -d "$APP_ROOT/.git" ]]; then
  git clone "$REPO_URL" "$APP_ROOT"
fi
cd "$APP_ROOT"
git fetch origin main
git checkout --force "$EXPECTED_SHA"
git rev-parse HEAD | grep -q "^${EXPECTED_SHA}" || git merge-base --is-ancestor "$EXPECTED_SHA" HEAD
AFTER=$(git rev-parse HEAD)
echo "DEMO_GIT=$AFTER"
echo "$AFTER" | grep -q "$EXPECTED_SHA" || [[ "$AFTER" == "$(git rev-parse "$EXPECTED_SHA")" ]]

composer install --no-dev --optimize-autoloader --no-interaction

# .env from example — never copy production .env
cp -n .env.example .env || true
php artisan key:generate --force >/dev/null

python3 - "$DB_PASS" "$INT_KEY" "$AUD_KEY" "$PAT_PASS" "$STAFF_PASS" "$DOC_PASS" "$ADM_PASS" <<'PY'
import sys
from pathlib import Path
db_pass, int_key, aud_key, pat, staff, doc, adm = sys.argv[1:8]
path = Path('.env')
text = path.read_text(encoding='utf-8').replace('\r\n','\n').replace('\r','\n')
lines = text.split('\n')

def upsert(lines, key, value):
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
 'LOG_CHANNEL': 'stack',
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
 'MEDIGUIDE_INTEGRITY_KEY': int_key,
 'MEDIGUIDE_INTEGRITY_KEY_VERSION': '1',
 'MEDIGUIDE_AUDIT_KEY': aud_key,
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
path.write_text('\n'.join(lines)+'\n', encoding='utf-8')
print('ENV_WRITTEN=yes')
PY
chmod 600 .env

# storage perms
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod 2750 bootstrap/cache

php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\RoleSeeder --force
php artisan mediguide:import-hospital-schedules --execute --bookable-slot-minutes=30 --no-interaction
php artisan mediguide:ensure-demo-booking-coverage \
  --confirm-local-demonstration \
  --confirm-database="$DB_NAME"
php artisan db:seed --class=Database\\Seeders\\CapstoneDemoAccountSeeder --force

# shellcheck source=/dev/null
source "$APP_ROOT/scripts/phase4j/lib/secure_config_cache.sh"
mediguide_secure_config_cache "$APP_ROOT"
mediguide_verify_cache_readable_by_www_data "$APP_ROOT"
stat -c 'config=%a %U:%G' bootstrap/cache/config.php

echo "=== NGINX + BASIC AUTH + TLS ==="
HTPASSWD=/etc/nginx/mediguide-demo.htpasswd
HASH=$(openssl passwd -apr1 "$BASIC_PASS")
printf 'defense:%s\n' "$HASH" | sudo tee "$HTPASSWD" >/dev/null
sudo chmod 640 "$HTPASSWD"
sudo chown root:www-data "$HTPASSWD"

sudo tee /etc/nginx/sites-available/demo.qmhchospital.com >/dev/null <<'NGX'
server {
    listen 80;
    listen [::]:80;
    server_name demo.qmhchospital.com;

    root /var/www/mediguide-demo/public;
    index index.php;

    add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    auth_basic "MediGuide Capstone Demonstration";
    auth_basic_user_file /etc/nginx/mediguide-demo.htpasswd;

    client_max_body_size 16M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGX
sudo ln -sfn /etc/nginx/sites-available/demo.qmhchospital.com /etc/nginx/sites-enabled/demo.qmhchospital.com
sudo nginx -t
sudo systemctl reload nginx
sudo certbot --nginx -d demo.qmhchospital.com --non-interactive --agree-tos --register-unsafely-without-email --redirect || \
  sudo certbot --nginx -d demo.qmhchospital.com --non-interactive --agree-tos -m admin@qmhchospital.com --redirect
sudo systemctl reload php8.4-fpm

echo "=== VERIFY ==="
php <<'PHP'
<?php
require '/var/www/mediguide-demo/vendor/autoload.php';
$app = require '/var/www/mediguide-demo/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Doctor;
use App\Services\AppointmentBookingService;
use App\Support\Demo\DemoBookingEnvironment;
echo 'DEMO_DB='.config('database.connections.mysql.database').PHP_EOL;
echo 'DEMO_INSTANCE='.var_export(config('hospital.demo_instance'), true).PHP_EOL;
echo 'DEMO_PROVISIONAL='.var_export(config('hospital.provisional_local_booking'), true).PHP_EOL;
echo 'AUTHORIZED='.var_export(DemoBookingEnvironment::isAuthorizedOnlineDemoInstance(), true).PHP_EOL;
echo 'PROV_ALLOWED='.var_export(DemoBookingEnvironment::provisionalBookingAllowed(), true).PHP_EOL;
$booking = app(AppointmentBookingService::class);
$n=0;$b=0;
foreach (Doctor::query()->where('status','active')->orderBy('id')->get() as $d) {
  $n++; if ($booking->nextAvailableSlot($d)) $b++;
}
echo "ACTIVE=$n BOOKABLE=$b".PHP_EOL;
if ($n !== 16 || $b !== 16) { fwrite(STDERR, "coverage fail\n"); exit(1); }
if (config('database.connections.mysql.database') === 'mediguide_demo') { fwrite(STDERR, "pointed at prod\n"); exit(1); }
PHP

# Production unchanged
php -r 'require "/var/www/mediguide/vendor/autoload.php"; $a=require "/var/www/mediguide/bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo "PROD_DB=".config("database.connections.mysql.database").PHP_EOL; echo "PROD_PROVISIONAL=".var_export(config("hospital.provisional_local_booking"), true).PHP_EOL; echo "PROD_APPT=".Illuminate\Support\Facades\DB::table("appointments")->count().PHP_EOL; echo "PROD_PAT=".Illuminate\Support\Facades\DB::table("patients")->count().PHP_EOL;'
[[ "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 https://qmhchospital.com/)" == "200" ]]
CODE=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -u "defense:${BASIC_PASS}" https://demo.qmhchospital.com/ || true)
echo "DEMO_HTTP=$CODE"
HEADERS=$(curl -sSI --max-time 20 -u "defense:${BASIC_PASS}" https://demo.qmhchospital.com/ || true)
echo "$HEADERS" | grep -qi 'x-robots-tag:.*noindex' && echo NOINDEX=yes || echo NOINDEX=no
echo "$HEADERS" | grep -qi 'strict-transport-security' && echo HSTS=yes || echo HSTS=check

echo "DEPLOY_DONE=yes CRED_FILE=$CRED_FILE"
echo "BASIC_AUTH_USER=defense (password in CRED_FILE only)"
