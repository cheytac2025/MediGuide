# MediGuide isolated demo — operator runbook (required now)

Automated deploy reached **interactive sudo password** and **missing DNS**. Complete these steps on an operator terminal with sudo and Hostinger hPanel access.

Approved tip: `e079e64305ea194064e64e5a6bc6d3e42f3b74bc`  
VPS IP: `189.31.38.114`  
Scripts already on VPS: `/home/mediguide/bootstrap_demo_app.sh`, `/home/mediguide/publish_demo_nginx.sh`

---

## 1. DNS (Hostinger hPanel) — do first

1. Open https://hpanel.hostinger.com → Domains → **qmhchospital.com** → DNS / DNS Zone Editor.
2. Add record:
   - **Type:** A  
   - **Name / Host:** `demo`  
   - **Points to:** `189.31.38.114`  
   - **TTL:** 300 (or default)
3. Wait until this resolves:

```bash
dig +short A demo.qmhchospital.com
# expect: 189.31.38.114
```

---

## 2. Bootstrap app + isolated DB (sudo password required)

SSH as `mediguide`, then:

```bash
EXPECTED_SHA='e079e64305ea194064e64e5a6bc6d3e42f3b74bc' \
APPROVAL_PHRASE='APPROVED deploy MediGuide isolated online capstone demonstration' \
bash /home/mediguide/bootstrap_demo_app.sh
```

This creates:

- `/var/www/mediguide-demo` at `e079e64`
- MySQL DB `mediguide_capstone_full_demo` + user `mediguide_demo_app` (no grants on `mediguide_demo`)
- New APP_KEY / integrity / audit keys
- 16/16 demo booking coverage
- Synthetic accounts with **random** passwords
- Credentials file: `/home/mediguide/secure-backups/demo/demo_credentials_*.txt` (mode 600)

Production `/var/www/mediguide` and `mediguide_demo` are not modified.

---

## 3. Publish Nginx + Basic Auth + TLS (after DNS)

```bash
APPROVAL_PHRASE='APPROVED deploy MediGuide isolated online capstone demonstration' \
bash /home/mediguide/publish_demo_nginx.sh
```

Expect:

- `https://demo.qmhchospital.com` → 401 without Basic Auth  
- 200 with user `defense` + password from credentials file  
- `X-Robots-Tag: noindex`  
- Production `https://qmhchospital.com` still 200  

---

## 4. Verify before sharing the link

```bash
# Demo coverage
cd /var/www/mediguide-demo
php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $b=app(App\Services\AppointmentBookingService::class); $n=0;$c=0; foreach(App\Models\Doctor::where("status","active")->get() as $d){$n++; if($b->nextAvailableSlot($d))$c++;} echo "ACTIVE=$n BOOKABLE=$c\n";'

# Isolation
php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("database.connections.mysql.database")."\n"; echo config("hospital.demo_instance")?"demo_instance=yes\n":"demo_instance=no\n";'

# Production unchanged
curl -sS -o /dev/null -w "%{http_code}\n" https://qmhchospital.com/
```

Open the credentials file (do not paste into chat logs if avoidable):

```bash
ls -lt /home/mediguide/secure-backups/demo/demo_credentials_*.txt | head -n1
```

---

## 5. Rollback

```bash
sudo rm -f /etc/nginx/sites-enabled/demo.qmhchospital.com
sudo nginx -t && sudo systemctl reload nginx
# Optional: leave /var/www/mediguide-demo and mediguide_capstone_full_demo for reuse
```

Production remains on `/var/www/mediguide` + `mediguide_demo` with provisional booking **false**.
