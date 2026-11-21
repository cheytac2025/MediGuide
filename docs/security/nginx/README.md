# MediGuide Nginx Security Header Deployment (Prepared)

**Status:** **Applied and verified** on production (`HSTS`, `CSP-Report-Only`, `Server: nginx` without version). CSP **enforcement** remains deferred.

## Findings addressed

| Finding | Change |
|---|---|
| FIND-001 | `Strict-Transport-Security: max-age=15552000` (180 days). No `includeSubDomains` / `preload`. |
| FIND-002 | `Content-Security-Policy-Report-Only` (strict; no `unsafe-inline` / `unsafe-eval`). |
| FIND-003 | `server_tokens off;` in `http {}` plus optional Server header clearing. |

## Compatibility notes

- Blade layouts load Bootstrap from `/vendor/bootstrap` (`'self'`).
- Google Fonts require `fonts.googleapis.com` / `fonts.gstatic.com`.
- Vite production assets are same-origin under `/build`.
- Inertia React app is same-origin.
- `resources/views/app.blade.php` contains inline `<script>` / `<style>` for appearance bootstrapping. Report-Only will report these; **Enforce mode must wait** until those snippets use nonces or hashes.

## Apply procedure (requires approval)

1. SSH to `srv2179431` as an operator with sudo.
2. Backup current site config: `sudo cp -a /etc/nginx/sites-available/... /home/mediguide/secure-backups/phase4j/nginx_*.bak`.
3. Ensure `http { server_tokens off; }` in `/etc/nginx/nginx.conf`.
4. Include `mediguide-security-headers.conf` inside the HTTPS `server { }` for `qmhchospital.com`.
5. `sudo nginx -t`
6. `sudo systemctl reload nginx`
7. Verify headers: `curl -sSI https://qmhchospital.com/login | grep -iE 'strict-transport|content-security|server:'`
8. Smoke: homepage/login 200; login page + one authenticated dashboard.

## Rollback

1. Remove include / restore backed-up site config.
2. `sudo nginx -t && sudo systemctl reload nginx`
3. Confirm previous header set returns.

## Approval phrase (suggested)

```text
APPROVED deploy MediGuide Nginx security headers FIND-001-003
```
