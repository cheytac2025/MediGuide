# MediGuide online capstone demonstration — deployment plan

**Status:** Prepared and tested locally. **Not publicly deployed.**  
**Code baseline:** `8cbe05d` + follow-up demo-instance gate commit (this package).  
**Proposed URL:** `https://demo.qmhchospital.com`  
**Production URL (unchanged):** `https://qmhchospital.com` → `/var/www/mediguide` → `mediguide_demo`

---

## 1. Preflight results (2026-11-21, read-only)

| Check | Result |
| --- | --- |
| VPS | `srv2179431` · 2 CPU · ~7 GiB RAM available · 94G disk free · load ~0 |
| Production health | HOME/LOGIN **200** · Git **`cdff369`** (demo code not yet on prod path) |
| Production DB | `mediguide_demo` · `HOSPITAL_PROVISIONAL_LOCAL_BOOKING=false` |
| `demo.qmhchospital.com` DNS | **NONE** — must be created before TLS/Nginx |
| Demo app dir `/var/www/mediguide-demo` | **absent** |
| Non-interactive sudo | **no** (operator password required for Nginx/FPM/certbot) |

---

## 2. Isolation model

| Concern | Production | Demonstration |
| --- | --- | --- |
| Path | `/var/www/mediguide` | `/var/www/mediguide-demo` |
| Database | `mediguide_demo` | `mediguide_capstone_full_demo` (or VPS twin) |
| PHP-FPM pool | default `www` | dedicated `mediguide-demo` pool + socket |
| Queue | `mediguide-queue` | `mediguide-demo-queue` (or sync for defense) |
| Session cookie | production name | `mediguide_demo_session` |
| Cache prefix | default | `mgdemo_` |
| `.env` | production secrets | **new** keys (never copy prod integrity/audit/APP_KEY) |
| Provisional booking | **false** | **true** only with `MEDIGUIDE_DEMO_INSTANCE=true` + exact `MEDIGUIDE_DEMO_DATABASE` |
| Mail | Gmail SMTP | `MAIL_MAILER=log` (or Mailpit) — no real patient inboxes |
| Claude | live key (prod) | mocked by default / placeholder key |

Application gate: `DemoBookingEnvironment::isAuthorizedOnlineDemoInstance()` requires:

1. `MEDIGUIDE_DEMO_INSTANCE=true`
2. `HOSPITAL_PROVISIONAL_LOCAL_BOOKING=true`
3. Connected DB name **exactly** equals `MEDIGUIDE_DEMO_DATABASE`
4. DB name **not** in blocked list (`mediguide_demo`, etc.)

Sticky UI banner: “CAPSTONE DEMONSTRATION — schedules and appointments are synthetic…”

---

## 3. Required approvals (before any public change)

1. **DNS:** create `demo.qmhchospital.com` A/AAAA → VPS IP  
2. **TLS:** Let’s Encrypt certificate for the demo hostname  
3. **Nginx:** new site config + optional HTTP basic auth  
4. **MySQL:** create isolated DB + least-privilege user (not `mediguide_demo`)  
5. **Filesystem:** clone/checkout app to `/var/www/mediguide-demo`  
6. **PHP-FPM pool + queue unit** (interactive sudo)  
7. **Separate secrets** generation (APP_KEY, integrity, audit)  
8. **Public exposure policy** (basic auth and/or IP allowlist)  

Until those are approved, keep the demo on local `mediguide_capstone_full_demo` only.

---

## 4. Local verified database

Already prepared on the developer workstation:

- Database: `mediguide_capstone_full_demo`
- 16/16 active doctors bookable with provisional demonstration slots
- Synthetic accounts via `CapstoneDemoAccountSeeder`:
  - `patient.demo@mediguide.test`
  - `staff.demo@mediguide.test`
  - `doctor.demo@mediguide.test`
  - `admin.demo@mediguide.test`
  - Password: `MediGuide@Demo2026` (local/demo only; rotate for any shared defense)

---

## 5. Deployment commands (approved window only)

```bash
# A. DNS must already resolve demo.qmhchospital.com to this VPS

# B. Create isolated DB (operator MySQL)
sudo mysql -e "CREATE DATABASE mediguide_capstone_full_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'mediguide_demo_app'@'127.0.0.1' IDENTIFIED BY '<generated>';"
sudo mysql -e "GRANT ALL ON mediguide_capstone_full_demo.* TO 'mediguide_demo_app'@'127.0.0.1'; FLUSH PRIVILEGES;"

# C. Application tree
sudo mkdir -p /var/www/mediguide-demo
sudo chown mediguide:www-data /var/www/mediguide-demo
git clone --branch main <repo> /var/www/mediguide-demo
cd /var/www/mediguide-demo
git checkout <approved-sha>   # 8cbe05d or later demo-gate commit

# D. Demo .env (never copy production secrets)
cp .env.example .env
# set APP_URL=https://demo.qmhchospital.com
# APP_ENV=production APP_DEBUG=false
# MEDIGUIDE_DEMO_INSTANCE=true
# MEDIGUIDE_DEMO_DATABASE=mediguide_capstone_full_demo
# HOSPITAL_PROVISIONAL_LOCAL_BOOKING=true
# HOSPITAL_DATA_SOURCE=imported
# MAIL_MAILER=log
# SESSION_COOKIE=mediguide_demo_session
# CACHE_PREFIX=mgdemo_
# ANTHROPIC_API_KEY=invalid-for-mocked-demo
# generate NEW APP_KEY / MEDIGUIDE_INTEGRITY_KEY / MEDIGUIDE_AUDIT_KEY

php artisan key:generate
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\RoleSeeder --force
php artisan mediguide:import-hospital-schedules --execute   # if import command requires local confirmations, use the approved demo bootstrap path
php artisan mediguide:ensure-demo-booking-coverage \
  --confirm-local-demonstration \
  --confirm-database=mediguide_capstone_full_demo
php artisan db:seed --class=Database\\Seeders\\CapstoneDemoAccountSeeder --force
# secure config cache helper from scripts/phase4j/lib/secure_config_cache.sh

# E. Nginx + TLS + optional basic auth (sudo)
# install docs/capstone/nginx/demo.qmhchospital.com.conf.example
sudo certbot --nginx -d demo.qmhchospital.com
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl reload php8.4-fpm
```

---

## 6. Access controls (recommended before public link)

1. Nginx `auth_basic` with a shared defense password (not a hospital account).  
2. Or Cloudflare Access / IP allowlist for committee networks.  
3. Keep Fortify registration disabled or unadvertised; use seeded synthetic accounts.  
4. Banner must remain visible on every layout.  
5. Do not index the demo site (`X-Robots-Tag: noindex`).

---

## 7. Rollback

```bash
# Disable demo vhost
sudo rm -f /etc/nginx/sites-enabled/demo.qmhchospital.com
sudo nginx -t && sudo systemctl reload nginx

# Optional: stop demo PHP pool / queue
sudo systemctl stop php8.4-fpm-mediguide-demo   # if dedicated
sudo systemctl stop mediguide-demo-queue        # if created

# Database: leave mediguide_capstone_full_demo intact or DROP only after backup
# Production /var/www/mediguide and mediguide_demo remain untouched
```

---

## 8. Verification checklist

- [ ] Production HOME/LOGIN still 200; provisional still false  
- [ ] Demo banner visible  
- [ ] 16/16 doctors bookable on demo DB  
- [ ] Book Appointment → wizard preselect → pending appointment  
- [ ] Staff confirm within Pending hospital mapping  
- [ ] Doctor consultation + patient instructions  
- [ ] Mail log only (no real patient email)  
- [ ] Integrity + audit verify on demo instance  
- [ ] Production appointment/patient counts unchanged  

---

## 9. Stop line

This document authorizes **preparation only**.  
Do **not** create DNS records, issue certificates, modify production Nginx, or create VPS databases until the approvals in §3 are granted in writing.
