# MediGuide Post-STRIDE Hardening — Production Deployment Plan

**Status:** Dependency + clinic UI deploy **EXECUTED** at `3df7c9efc30d770cf3a9806ed0e96fd65d537a16` (2026-11-20 UTC). Nginx headers and audit checkpoint refresh remain **NOT EXECUTED** — require separate approval. PHP-FPM reload still needs interactive sudo.

## Exact local commit contents (to be committed/pushed before deploy)

Expected artifacts:

- `composer.json` / `composer.lock` — Laravel `^13.40.0`, commonmark `^2.10.2`, Symfony 7.4.23 security lineup; `composer audit` clean
- `docs/security/**` — headers, ops scripts, updated STRIDE report

Production currently: `e2f3a364d9b421c9e1c04bf7a453fa985a3071cc`  
Deploy target: **new Git commit after this hardening is committed** (fill SHA at approval time).

## Pre-deployment health checks

1. Host `srv2179431`, DB `mediguide_demo`
2. `HOME`/`LOGIN` HTTP 200
3. `php artisan mediguide:integrity-verify --strict`
4. `php artisan mediguide:audit-verify`
5. Flags unchanged: `AUTH_REQUIRE_EMAIL_OTP_ALL_ROLES=false`, patient verification preserved
6. OTP pilots 33/35/36/37 remain opted-in
7. FPM / queue / Nginx active

## Backups (before any change)

1. Fresh encrypted DB dump → `/home/mediguide/secure-backups/phase4j/mediguide_demo_<stamp>.sql.enc`
2. Checkpoint file copy + encrypted checkpoint backup (prep script)
3. Nginx site config backup if applying headers

## Dependency deployment

1. `git fetch && git merge --ff-only <approved-sha>`
2. `composer install --no-dev --optimize-autoloader` (production)
3. **Do not** run `composer update` on production
4. No migrations expected for this hardening batch
5. Secure config cache rebuild (Option D / `mediguide_secure_config_cache`)
6. Verify `bootstrap/cache/config.php` is `640 mediguide:www-data`
7. Reload PHP-FPM (`sudo systemctl reload php8.4-fpm`)

## Nginx security headers (separate approval recommended)

1. Apply `docs/security/nginx/mediguide-security-headers.conf` per README
2. `server_tokens off;`
3. `nginx -t` then reload
4. Verify HSTS + CSP-Report-Only headers
5. Smoke auth pages

## Audit checkpoint refresh (separate approval)

1. Run `docs/security/ops/audit_checkpoint_refresh_prep.sh prep`
2. On approval: `APPROVAL_PHRASE='APPROVED refresh MediGuide audit checkpoint' ... execute`
3. Re-run `mediguide:audit-verify`

## Post-deployment checks

1. HOME/LOGIN 200
2. Role dashboards unauth 302
3. Integrity + audit verify
4. `composer show` versions on VPS match lock
5. Optional: `composer audit` on VPS after install
6. Confirm auth flags unchanged
7. Confirm appt/patient counts unchanged

## Rollback

### App/deps

1. `git reset --hard <previous-sha>` (e.g. `e2f3a36`)
2. `composer install --no-dev --optimize-autoloader`
3. Secure config cache rebuild
4. PHP-FPM reload
5. Smoke HOME/LOGIN + integrity/audit

### Nginx headers

1. Restore prior site config backup
2. `nginx -t && systemctl reload nginx`

### Checkpoint

1. Do not delete events
2. If refresh failed mid-way, restore pre-refresh checkpoint file from encrypted backup using stored pass file
3. Re-verify chain

## Suggested approval phrases

```text
APPROVED deploy MediGuide post-STRIDE dependency hardening
APPROVED deploy MediGuide Nginx security headers FIND-001-003
APPROVED refresh MediGuide audit checkpoint
```

Keep global OTP disabled. No account email changes in this deploy.
