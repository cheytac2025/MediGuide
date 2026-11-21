#!/usr/bin/env bash
# Phase 2: Nginx + basic auth + TLS for demo.qmhchospital.com
# Requires DNS A record already pointing to this VPS.
set -euo pipefail

APPROVAL_REQUIRED='APPROVED deploy MediGuide isolated online capstone demonstration'
DEMO_HOST=demo.qmhchospital.com
APP_ROOT=/var/www/mediguide-demo
CRED_DIR=/home/mediguide/secure-backups/demo

if [[ "${APPROVAL_PHRASE:-}" != "$APPROVAL_REQUIRED" ]]; then
  echo "Refusing: set APPROVAL_PHRASE='$APPROVAL_REQUIRED'"
  exit 2
fi
[[ "$(hostname)" == "srv2179431" ]] || exit 1
[[ -d "$APP_ROOT" ]] || { echo "FAIL missing $APP_ROOT — run bootstrap first"; exit 1; }

if ! getent hosts "$DEMO_HOST" >/dev/null 2>&1; then
  echo "FAIL DNS missing for $DEMO_HOST"
  exit 1
fi
DEMO_IP=$(getent hosts "$DEMO_HOST" | awk '{print $1}' | head -n1)
PUBLIC_IP=$(curl -sS --max-time 10 https://api.ipify.org)
[[ "$DEMO_IP" == "$PUBLIC_IP" ]] || { echo "FAIL DNS $DEMO_IP != $PUBLIC_IP"; exit 1; }

BASIC_TMP=$(ls -1t "$CRED_DIR"/basic_pass_*.tmp 2>/dev/null | head -n1 || true)
[[ -n "$BASIC_TMP" && -f "$BASIC_TMP" ]] || { echo "FAIL missing basic pass tmp from bootstrap"; exit 1; }
BASIC_PASS=$(cat "$BASIC_TMP")

# Prefer openssl apr1 hash — avoids apt-get / apache2-utils / Ondrej PPA.
HTPASSWD=/etc/nginx/mediguide-demo.htpasswd
HASH=$(openssl passwd -apr1 "$BASIC_PASS")
printf 'defense:%s\n' "$HASH" | sudo tee "$HTPASSWD" >/dev/null
sudo chmod 640 "$HTPASSWD"
sudo chown root:www-data "$HTPASSWD"
echo "HTPASSWD_METHOD=openssl_apr1"

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

# TLS — requires DNS
if [[ -d /etc/letsencrypt/live/qmhchospital.com ]]; then
  # Prefer registering with existing Hostinger LE account email if present in renewal config
  EMAIL=$(sudo grep -h email /etc/letsencrypt/renewal/qmhchospital.com.conf 2>/dev/null | head -n1 | cut -d= -f2 | tr -d ' ' || true)
fi
EMAIL=${EMAIL:-admin@qmhchospital.com}
sudo certbot --nginx -d demo.qmhchospital.com --non-interactive --agree-tos -m "$EMAIL" --redirect
sudo systemctl reload php8.4-fpm
sudo systemctl reload nginx

# Ensure auth_basic still present after certbot rewrite
if ! sudo grep -q 'auth_basic' /etc/nginx/sites-available/demo.qmhchospital.com; then
  echo "WARN certbot may have rewritten vhost; re-injecting basic auth"
  sudo sed -i '/server_name demo.qmhchospital.com;/a\    auth_basic "MediGuide Capstone Demonstration";\n    auth_basic_user_file /etc/nginx/mediguide-demo.htpasswd;' /etc/nginx/sites-available/demo.qmhchospital.com
  sudo nginx -t && sudo systemctl reload nginx
fi

echo "=== HTTP VERIFY ==="
UNAUTH=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 https://demo.qmhchospital.com/ || true)
AUTH=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -u "defense:${BASIC_PASS}" https://demo.qmhchospital.com/ || true)
echo "UNAUTH=$UNAUTH AUTH=$AUTH"
HEADERS=$(curl -sSI --max-time 20 -u "defense:${BASIC_PASS}" https://demo.qmhchospital.com/ || true)
echo "$HEADERS" | grep -qi 'x-robots-tag:.*noindex' && echo NOINDEX=yes || echo NOINDEX=no
[[ "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 https://qmhchospital.com/)" == "200" ]] && echo PROD_OK=yes

# shred temp basic pass file after publish (password remains in CRED_FILE)
rm -f "$BASIC_TMP"
echo "PUBLISH_DONE=yes"
echo "Access: https://demo.qmhchospital.com (Basic Auth user=defense; password in latest CRED_FILE)"
