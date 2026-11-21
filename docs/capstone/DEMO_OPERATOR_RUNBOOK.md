# MediGuide isolated demo — operator runbook (required now)

Automated deploy reached **interactive sudo password** and **missing DNS**. Complete these steps on an operator terminal with sudo and Hostinger hPanel access.

| Item | Value |
| --- | --- |
| Deploy tip | `0d6703f` on `origin/main` (includes demo gates + scripts + this runbook) |
| VPS IP | `189.31.38.114` |
| Scripts on VPS | `/home/mediguide/bootstrap_demo_app.sh`, `/home/mediguide/publish_demo_nginx.sh` |

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

## 2. Refresh scripts (optional) and bootstrap app + DB

SSH as `mediguide`. If scripts are older than `0d6703f`, re-upload from the repo, then:

```bash
# Resolve full SHA of origin/main
FULL_SHA=$(git ls-remote https://github.com/cheytac2025/MediGuide.git refs/heads/main | awk '{print $1}')
echo "FULL_SHA=$FULL_SHA"

EXPECTED_SHA="$FULL_SHA" \
APPROVAL_PHRASE='APPROVED deploy MediGuide isolated online capstone demonstration' \
bash /home/mediguide/bootstrap_demo_app.sh
```

Enter the **mediguide sudo password** when prompted.

This creates:

- `/var/www/mediguide-demo` at the approved tip  
- MySQL DB `mediguide_capstone_full_demo` + user `mediguide_demo_app` (no grants on `mediguide_demo`)  
- New APP_KEY / integrity / audit keys  
- 16/16 demo booking coverage  
- Synthetic accounts with **random** passwords  
- Credentials: `/home/mediguide/secure-backups/demo/demo_credentials_*.txt` (mode 600)

Production `/var/www/mediguide` and `mediguide_demo` are not modified.

Discard any earlier incomplete credentials file from a failed run; use only the file printed as `CRED_FILE=` by a successful bootstrap.

---

## 3. Publish Nginx + Basic Auth + TLS (after DNS)

```bash
APPROVAL_PHRASE='APPROVED deploy MediGuide isolated online capstone demonstration' \
bash /home/mediguide/publish_demo_nginx.sh
```

Expect:

- `https://demo.qmhchospital.com` → **401** without Basic Auth  
- **200** with user `defense` + password from credentials file  
- `X-Robots-Tag: noindex`  
- Production `https://qmhchospital.com` still **200**

---

## 4. Verify before sharing the link

```bash
cd /var/www/mediguide-demo
php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $b=app(App\Services\AppointmentBookingService::class); $n=0;$c=0; foreach(App\Models\Doctor::where("status","active")->get() as $d){$n++; if($b->nextAvailableSlot($d))$c++;} echo "ACTIVE=$n BOOKABLE=$c\n"; echo "DB=".config("database.connections.mysql.database")."\n";'

curl -sS -o /dev/null -w "%{http_code}\n" https://qmhchospital.com/
ls -lt /home/mediguide/secure-backups/demo/demo_credentials_*.txt | head -n1
```

---

## 5. Rollback

```bash
sudo rm -f /etc/nginx/sites-enabled/demo.qmhchospital.com
sudo nginx -t && sudo systemctl reload nginx
```

Production remains on `/var/www/mediguide` + `mediguide_demo` with provisional booking **false**.
