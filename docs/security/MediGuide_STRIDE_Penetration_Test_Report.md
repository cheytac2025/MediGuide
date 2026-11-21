# MediGuide STRIDE Threat Modeling and Controlled Penetration Test Report

| Field | Value |
|---|---|
| Document | MediGuide Capstone Security Assessment |
| Version | 1.4 (Nginx headers verified; AI Front Desk symptom improvements) |
| Baseline version | 1.0 (21 November 2026 initial STRIDE/PT) |
| Report date | 21 November 2026 |
| Classification | Capstone / Internal Security Assessment |
| Target | https://qmhchospital.com |
| Application path | /var/www/mediguide |
| Host | srv2179431 |
| Database | mediguide_demo |
| Production Git (pre-AI deploy) | c1fc1b21ce8b8db5a38c6edab2fcae11799cb787 |
| Deployed packages | Laravel 13.40.0 · commonmark 2.10.2 · Symfony http-foundation/serializer 7.4.23 |
| Assessor role | Senior penetration tester / Laravel security engineer (AI-assisted) |

**Disclaimer:** This report documents a controlled, evidence-based security assessment. It is **not** a certification of absolute security and does **not** authorize production exploitation. Live verification and isolated testing are distinguished throughout.

---

## 1. Executive Summary

MediGuide was assessed using Microsoft STRIDE threat modeling, OWASP Top 10 / WSTG / ASVS-aligned review, dependency advisory analysis (`composer audit`), isolated PHPUnit security suites, and low-impact read-only production verification.

### Key results

| Area | Result |
|---|---|
| Isolated security-focused PHPUnit | **293 passed** / 2,265 assertions |
| Full PHPUnit regression | **1,143 passed** / 7,960 assertions |
| Live four-role email OTP | **PASS** (baseline + reconfirmed opt-in scope) |
| Strict integrity | **PASS** (11 appointments, 5 patients valid) |
| Audit chain | **PASS** (105/105 valid) |
| Confirmed application exploits | **None demonstrated** under rules of engagement |
| Confirmed vulnerabilities / weaknesses | **8 findings** (dependency, header, operational) |
| Capstone demo security posture | **ACCEPTABLE with residual risks** |
| Broad hospital mandatory-MFA readiness | **NOT READY** |

### Severity distribution (confirmed findings)

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 2 (dependency / operational MFA readiness) |
| Medium | 3 |
| Low | 3 |

---

## 2. Project and System Overview

MediGuide is an AI-assisted hospital navigation and appointment management system supporting four roles: Patient, Hospital Staff, Doctor, and IT Administrator. Production runs on Nginx + PHP-FPM with Gmail SMTP, Anthropic Claude AI, database sessions, encrypted sensitive fields, HMAC record integrity, and a tamper-evident audit chain.

Existing controls observed during assessment include email verification (patient-focused), account invitations, account-scoped email OTP, Fortify TOTP/passkeys, RBAC, department/clinic scoping, CSRF, HTTPS, login/OTP throttling, and AI emergency safety detectors.

---

## 3. Assessment Objectives

1. Model threats across authentication, clinical workflows, AI, mail, and audit surfaces using STRIDE.
2. Validate access control, authentication, integrity, and logging with isolated tests.
3. Perform non-destructive live checks (HTTP status, security headers, integrity, audit, config posture).
4. Identify confirmed weaknesses, operational risks, and untested areas.
5. Produce a capstone-ready PDF/Markdown report with sanitized evidence.

---

## 4. Scope and Rules of Engagement

### In scope

- MediGuide application source and configuration
- Isolated PHPUnit / synthetic fixtures
- Read-only production verification on srv2179431 / mediguide_demo
- Low-impact HTTPS and response-header inspection
- Composer dependency advisories
- AI prompt-injection / emergency safety in isolated/mocked tests

### Out of scope (without separate approval)

- Destructive or high-volume production attacks
- Credential spraying / brute force against live users
- Exfiltration of patient data
- Production database writes
- Unapproved emails or Anthropic calls
- Nginx/firewall/auth-flag/encryption-key changes

---

## 5. Testing Methodology

1. **Baseline reuse:** Final Capstone System Acceptance Report values independently rechecked where possible.
2. **STRIDE modeling:** Assets, DFDs, trust boundaries, per-category threats and tests.
3. **OWASP mapping:** Top 10, WSTG techniques, ASVS-aligned control expectations.
4. **Isolated penetration-style tests:** Authorization, OTP, invitations, audit/integrity, AI safety.
5. **Live read-only verification:** HTTPS, headers, flags, integrity, audit, service health.
6. **Dependency audit:** `composer audit` against locked versions.
7. **Evidence rules:** PASS only with evidence; otherwise INCONCLUSIVE / NOT TESTED.

---

## 6. System Architecture Diagram (logical)

```
[Patient Browser] ──HTTPS──┐
[Staff Browser]   ──HTTPS──┤
[Doctor Browser]  ──HTTPS──┼──► [Nginx TLS] ──► [Laravel 13 / PHP-FPM]
[Admin Browser]   ──HTTPS──┘         │
                                     ├── Auth (Fortify, OTP, TOTP, Passkeys)
                                     ├── RBAC + department/clinic scopes
                                     ├── AI Front Desk service
                                     ├── Queue worker (database)
                                     ├── Audit trail + integrity services
                                     │
                     ┌───────────────┼────────────────┐
                     ▼               ▼                ▼
               [MySQL 8]      [Gmail SMTP]    [Anthropic Claude API]
               users, appts,   notifications   guidance (configured)
               audit_events,   invitations
               integrity tags
```

---

## 7. Data-Flow Diagram and Trust Boundaries

### Trust boundaries

| Boundary | Description |
|---|---|
| TB1 | Public Internet ↔ Nginx/TLS termination |
| TB2 | Unauthenticated ↔ Authenticated session (post login/OTP/TOTP/passkey) |
| TB3 | Role boundaries (Patient / Staff / Doctor / Admin) |
| TB4 | Application ↔ MySQL (credentials, encrypted fields) |
| TB5 | Application ↔ Gmail SMTP |
| TB6 | Application ↔ Anthropic API |
| TB7 | Audit checkpoint file store outside web root |

### Sensitive data flows

- Credentials / session cookies (Secure, HttpOnly observed on session cookie)
- Hashed OTP / invitation tokens (not plaintext at rest)
- Patient instructions (encryption covered by isolated consultation tests)
- AI conversation content (minimization/safety covered in isolated AI tests; live Anthropic calls not repeated)

---

## 8. STRIDE Threat Matrix

| ID | Category | Threat | Asset / Entry | Control observed | Residual risk | Status |
|---|---|---|---|---|---|---|
| S-01 | Spoofing | Password login without second factor | /login | Account-scoped OTP for opted-in users; Fortify TOTP available | Global OTP off; most accounts not OTP-ready | Mitigated (pilot) / Open (fleet) |
| S-02 | Spoofing | OTP replay / guess | OTP challenge | Hashing, consume-on-success, attempt lock, TTL (isolated PASS) | None material for opted-in flow | Mitigated |
| S-03 | Spoofing | Invitation token theft/reuse | Activation links | Hashed token, single-use, expiry (isolated + live invitations) | Email channel compromise | Mitigated |
| S-04 | Spoofing | Session hijack | Cookies | Secure + HttpOnly + SameSite=lax; session driver=database | XSS still relevant → CSP gap | Partial |
| T-01 | Tampering | Appointment/record alteration | Clinical APIs | HMAC integrity verify LIVE PASS | Checkpoint lag for audit tail | Mitigated |
| T-02 | Tampering | Mass assignment / param tampering | Controllers | Form requests + role gates (isolated) | Standard residual | Mitigated |
| R-01 | Repudiation | Deny auth/admin actions | audit_events | OTP/auth/admin events; chain 105/105 | 51 events after checkpoint | Partial |
| R-02 | Repudiation | Delete/alter audit history | DB/files | Chain HMAC + checkpoint protection | Tail uncovered by checkpoint | Partial |
| I-01 | Info disclosure | Cross-role data access | Dashboards/APIs | RBAC + staff dept scope isolated PASS; live unauth 302 | Live horizontal IDOR not re-fuzzed on prod | Mitigated (isolated) |
| I-02 | Info disclosure | Debug/secrets leakage | HTTP/logs | APP_DEBUG=false; config/.env 640 | Server version header | Partial |
| I-03 | Info disclosure | OTP/token in logs/audit | Logging | Audit context scrub tests; OTP plaintext scan negative | Ongoing monitoring needed | Mitigated |
| D-01 | DoS | Login/OTP flooding | Auth endpoints | Login 5/min; email-otp 5/min; verify-send 3/min | No live stress test | Mitigated (config) / NOT TESTED (load) |
| D-02 | DoS | Dependency parser DoS | Markdown/YAML | Advisories present in locked deps | Patch required | Open |
| E-01 | Elevation | Patient→Admin | Routes | Role middleware; public registration patient-only tests | Continuous regression | Mitigated |
| E-02 | Elevation | Staff cross-department | Staff APIs | Department scope suite PASS | — | Mitigated |
| E-03 | Elevation | Unauthorized admin create | Public/API | No public admin store; controlled invitation path | Ops process risk | Mitigated |

---

## 9. OWASP Security Assessment

| OWASP Top 10 (2021-aligned) | Assessment | Evidence class | Result |
|---|---|---|---|
| A01 Broken Access Control | Role + department/clinic scoping tests; live unauth 302 | Isolated + Live | **PASS** (with residual IDOR live fuzz NOT TESTED) |
| A02 Cryptographic Failures | Encrypted instructions tests; hashed OTP/invites; HTTPS | Isolated + Live | **PASS** |
| A03 Injection | Eloquent/query bindings; XSS text encoding tests; no intrusive SQLi on prod | Isolated | **PASS** (prod payload injection NOT TESTED) |
| A04 Insecure Design | Invitation + OTP design reviewed; passkey bypasses email OTP by design | Review | **PASS with design notes** |
| A05 Security Misconfiguration | Missing HSTS/CSP; Server banner; APP_DEBUG=false | Live | **FAIL (headers)** / PASS (debug off) |
| A06 Vulnerable Components | composer audit advisories on locked packages | Live tooling | **FAIL (advisories)** |
| A07 Identification/Auth Failures | OTP/TOTP/passkey/invite/password-reset suites; throttles | Isolated + Live pilots | **PASS** (fleet MFA incomplete) |
| A08 Software/Data Integrity | HMAC integrity + audit chain | Live + Isolated | **PASS** |
| A09 Security Logging/Monitoring | Audit events; checkpoint lag | Live | **PASS with gap** |
| A10 SSRF | No dedicated SSRF suite against outbound fetchers | — | **NOT TESTED** |

---

## 10. Penetration Test Case Matrix (summary)

Environment key: **I** = Isolated PHPUnit/fixtures · **L** = Live read-only · **R** = Code/config review

| Test ID | STRIDE | Objective | Env | Result | Evidence |
|---|---|---|---|---|---|
| PT-001 | S | OTP invalid/expired/replay rejected | I | PASS | EmailOtpChallengeTest |
| PT-002 | S | OTP max-attempt lock | I | PASS | EmailOtpChallengeTest |
| PT-003 | S | OTP mail failure fails closed | I | PASS | EmailOtpChallengeTest |
| PT-004 | S | Session regenerate on OTP success | I | PASS | EmailOtpChallengeTest |
| PT-005 | S | Four-role account-scoped OTP | I+L | PASS | Tests + live pilots 33/35/36/37 |
| PT-006 | S | Invitation expire/revoke/reuse rejected | I | PASS | AdminUserAccountTest |
| PT-007 | S | Password reset token misuse resistance | I | PASS | PasswordResetTest |
| PT-008 | S | Login throttle configured (5/min) | R | PASS | FortifyServiceProvider |
| PT-009 | T | Appointment HMAC integrity | L | PASS | mediguide:integrity-verify --strict |
| PT-010 | T | Consultation publish preserves integrity | I | PASS | ConsultationDocumentationTest |
| PT-011 | R | Audit chain tamper detection | I+L | PASS | AuditTrailTest + audit-verify |
| PT-012 | R | Checkpoint detects truncation/disagreement | I | PASS | AuditCheckpointTest |
| PT-013 | R | Audit context drops sensitive values | I | PASS | AuditTrailTest |
| PT-014 | I | XSS characters not executed as HTML in AI suggest | I | PASS | AiSuggestedDoctorsTest |
| PT-015 | I | APP_DEBUG false; secrets file modes 640 | L | PASS | Production snapshot |
| PT-016 | I | OTP plaintext absent from audit | L | PASS | Post-pilot scan |
| PT-017 | I | Security response headers completeness | L | FAIL | Missing HSTS/CSP |
| PT-018 | D | OTP resend cooldown | I | PASS | EmailOtpChallengeTest |
| PT-019 | D | Dependency DoS advisories present | R | FAIL | composer audit |
| PT-020 | E | Patient/Doctor/Admin forbidden on staff routes | I | PASS | HospitalStaffDepartmentScopeTest |
| PT-021 | E | Staff cannot access unassigned department | I | PASS | HospitalStaffDepartmentScopeTest |
| PT-022 | E | Public registration cannot assign privileged role | I | PASS | AdminDashboardTest |
| PT-023 | E | Unauth role dashboards redirect | L | PASS | HTTP 302 |
| PT-024 | E | Unauthorized admin UI create route absent | R+L | PASS | No store-admin route |
| PT-025 | I/E | AI emergency / prompt safety detectors | I | PASS | AiUrgent/Safety/Navigation suites |
| PT-026 | — | Live intrusive SQLi/XSS payloads | — | NOT TESTED | RoE prohibition |
| PT-027 | D | Production stress / flood | — | NOT TESTED | RoE prohibition |
| PT-028 | A10 | SSRF against internal services | — | NOT TESTED | No approved harness |

Security-focused suite execution (this assessment): **293 passed**. Full regression: **1,143 passed**.

---

## 11. Detailed Live Verification Snapshot

| Check | Value |
|---|---|
| Git | e2f3a364d9b421c9e1c04bf7a453fa985a3071cc |
| HOME/LOGIN | HTTP 200 |
| APP_DEBUG | false |
| Session | database; Secure; HttpOnly; SameSite=lax |
| Observed headers | X-Frame-Options=SAMEORIGIN; X-Content-Type-Options=nosniff; Referrer-Policy=strict-origin-when-cross-origin |
| Missing headers | Strict-Transport-Security; Content-Security-Policy |
| Server banner | nginx/1.24.0 (Ubuntu) |
| OTP flags | EMAIL_OTP_ENABLED=true; REQUIRE_EMAIL_OTP_ALL_ROLES=false |
| OTP opt-ins | 33,35,36,37 only |
| Reusable OTP challenges | 0 |
| Integrity | appointment 11 valid / patient 5 valid |
| Audit | 105/105 valid; checkpoint seq 54; lag 51 |
| Active users inbox heuristic | 35 active; 8 Gmail; 26 unverified; 4 inaccessible-domain heuristic |
| Services | php8.4-fpm, mediguide-queue, nginx active |
| config.php / .env | mode 640 |

Cookie values collected during header inspection were **discarded** and are **not** reproduced in this report.

---

## 12. Confirmed Vulnerabilities and Weaknesses

### FIND-001 — Missing HTTP Strict Transport Security (HSTS)

| Field | Detail |
|---|---|
| Component | Nginx / HTTPS responses |
| STRIDE | Tampering / Spoofing (transport) |
| OWASP | A05 Security Misconfiguration |
| Severity | Medium |
| Evidence | Live response headers for `/login` lacked `Strict-Transport-Security` |
| Impact | Users on hostile networks more exposed to SSL-stripping / downgrade attempts |
| Root cause | HSTS not configured at TLS edge |
| Remediation | Add HSTS with appropriate max-age/includeSubDomains after confirming HTTPS-only |
| Retest | Pending (requires Nginx change approval) |
| CVSS | 5.3 AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N (defensible for missing transport hardening) |

### FIND-002 — Missing Content-Security-Policy

| Field | Detail |
|---|---|
| Component | HTTP security headers |
| STRIDE | Information Disclosure / Spoofing |
| OWASP | A05 / XSS defense-in-depth |
| Severity | Medium |
| Evidence | Live headers lacked `Content-Security-Policy` |
| Impact | Reduced mitigation if an XSS sink is introduced later |
| Root cause | CSP not deployed |
| Remediation | Deploy restrictive CSP compatible with Vite/Inertia assets |
| Retest | Pending |

### FIND-003 — Server version disclosure

| Field | Detail |
|---|---|
| Component | Nginx `Server` header |
| STRIDE | Information Disclosure |
| OWASP | A05 |
| Severity | Low |
| Evidence | `Server: nginx/1.24.0 (Ubuntu)` |
| Impact | Aids targeted exploit selection |
| Remediation | `server_tokens off;` / hide version |
| Retest | Pending |

### FIND-004 — Vulnerable dependency: league/commonmark (DoS / HTML bypass advisories)

| Field | Detail |
|---|---|
| Component | league/commonmark 2.10.1 |
| STRIDE | Denial of Service / Information Disclosure |
| OWASP | A06 |
| Severity | High (advisory severity) |
| Evidence | `composer audit` GHSA-3q6v-r5mr-hxv8 (high), GHSA-97jj-33gv-5xf9 (medium) |
| Impact | Crafted markdown may cause CPU DoS or bypass raw-HTML disallow rules if rendered |
| Root cause | Locked package within affected range |
| Remediation | Upgrade commonmark beyond 2.10.1 after regression tests |
| Retest | Not fixed in this assessment (no deploy) |

### FIND-005 — Vulnerable dependency: laravel/framework task output injection

| Field | Detail |
|---|---|
| Component | laravel/framework v13.32.0 |
| STRIDE | Tampering |
| OWASP | A06 |
| Severity | Low (advisory) |
| Evidence | CVE-2026-128083 / GHSA-63hm-g96r-2m2q; affected `>=13.0.0,<13.40.0` |
| Impact | Concurrency process task output injection in specific console/process paths |
| Remediation | Upgrade to >=13.40.0 when approved |
| Retest | Pending |

### FIND-006 — Vulnerable dependencies: symfony/http-foundation and symfony/yaml

| Field | Detail |
|---|---|
| Component | symfony/http-foundation v8.0.15; symfony/yaml v8.0.15 |
| STRIDE | Spoofing / DoS |
| OWASP | A06 |
| Severity | Medium–High (advisory-dependent) |
| Evidence | composer audit CVE-2026-121619, CVE-2026-118514, CVE-2026-123792, CVE-2026-109413 |
| Impact | Trusted-proxy edge cases; YAML parse DoS if untrusted YAML processed |
| Remediation | Upgrade Symfony components via Laravel-compatible releases |
| Retest | Pending |

### FIND-007 — Audit checkpoint coverage lag

| Field | Detail |
|---|---|
| Component | Audit checkpoint |
| STRIDE | Repudiation |
| OWASP | A09 |
| Severity | Medium (operational control gap) |
| Evidence | Checkpoint sequence 54; 51 later events uncovered |
| Impact | Tail deletions may be harder to detect without refreshed checkpoint |
| Remediation | Approved checkpoint refresh + encrypted backup |
| Retest | Pending approval |

### FIND-008 — Incomplete fleet email/MFA readiness

| Field | Detail |
|---|---|
| Component | Account email hygiene / OTP rollout |
| STRIDE | Spoofing |
| OWASP | A07 |
| Severity | High (operational) for mandatory OTP enablement |
| Evidence | 35 active users; 8 Gmail; 26 unverified; 4 OTP-ready pilots only; global OTP false |
| Impact | Enabling mandatory OTP would lock out many accounts |
| Remediation | Inbox remediation / claim invitations before `AUTH_REQUIRE_EMAIL_OTP_ALL_ROLES=true` |
| Retest | N/A until remediation |

---

## 13. Potential Threats (not confirmed exploits)

1. Passkey authentication intentionally bypasses email-OTP LoginResponse gating — acceptable if passkeys are strongly bound; residual if enrollment is weak.
2. `AUTH_ENFORCE_EMAIL_VERIFICATION=false` leaves privileged unverified access paths for security settings unless other controls apply.
3. Email channel compromise remains a shared-threat for OTP and invitations.
4. Live horizontal IDOR fuzzing against production IDs was not performed (RoE).

---

## 14. Operational Risks

1. Synthetic pilots 33/35/36/37 remain OTP-enabled.
2. Encrypted DB backup present but dated 2026-11-20 15:07 UTC relative to later auth events.
3. No production stress validation of queue/SMTP under failure storms.

---

## 15. Untested Areas

- Production intrusive injection payloads
- SSRF
- Full ASVS Level 2 manual checklist
- Live Anthropic prompt-injection (no approved live calls this run)
- Wireless/network-layer attacks
- Social engineering of hospital staff

---

## 16. Existing Security Controls (validated)

| Control | Evidence |
|---|---|
| HTTPS | Live 200 over TLS |
| CSRF | Framework default + isolated CSRF rejection tests |
| RBAC | Role middleware + suites |
| Department scope | HospitalStaffDepartmentScopeTest |
| Email OTP | Isolated + four-role live |
| Invitations | Isolated + live staff/doctor/admin |
| Integrity HMAC | Live strict verify |
| Audit chain | Live + isolated tamper tests |
| Checkpoint crypto | AuditCheckpointTest |
| Throttles | login/email-otp/2FA/passkeys/verify-send |
| Debug off | APP_DEBUG=false |
| Secret file perms | 640 |
| AI safety detectors | Isolated unit/feature suites |

---

## 17. Remediation Plan

### P0 — Critical
- None confirmed.

### P1 — High
1. Patch `league/commonmark` and Symfony components (FIND-004, FIND-006).
2. Complete inbox remediation before any global OTP mandate (FIND-008).

### P2 — Medium
3. Enable HSTS (FIND-001) — requires Nginx approval.
4. Deploy CSP (FIND-002).
5. Refresh audit checkpoint + backup (FIND-007).
6. Upgrade Laravel to patched release (FIND-005) as part of dependency maintenance.

### P3 — Low
7. Hide Nginx version (FIND-003).
8. Deactivate/cleanup synthetic pilots after demo (approval required).
9. Schedule fresh encrypted DB backup.

**No production fixes were deployed in this assessment.**

---

## 18. Retest Results

| Finding | Retest |
|---|---|
| FIND-001..007 code/config fixes | Not applied — pending approval |
| Auth/OTP/RBAC/integrity controls | Revalidated PASS via suites + live checks |

---

## 19. Residual Risks

1. Dependency advisories remain until patched.
2. Header hardening incomplete.
3. Checkpoint lag remains.
4. Mandatory MFA not fleet-ready.
5. Untested SSRF / live injection / stress surfaces.

---

## 20. Security Readiness Conclusion

| Question | Verdict |
|---|---|
| Capstone demonstration security posture | **ACCEPTABLE** — strong auth/RBAC/integrity/audit evidence; residual risks documented |
| Penetration-test certified / fully secure | **NO** — not claimed |
| Broad hospital production with mandatory OTP | **NOT READY** |
| Critical exploitable findings under RoE | **None confirmed** |

---

## 21. References

1. Microsoft, *The STRIDE Threat Model*.
2. OWASP Top 10 (2021).
3. OWASP Web Security Testing Guide.
4. OWASP Application Security Verification Standard (ASVS).
5. FIRST, *Common Vulnerability Scoring System v3.1*.
6. Laravel Fortify documentation.
7. Composer security advisories / GitHub Advisory Database.
8. MediGuide Final Capstone System Acceptance Report (baseline evidence).

---

## Appendix A — Evidence Index

| Evidence ID | Description |
|---|---|
| E-PHPUNIT-SEC | 293 passed security-focused suites |
| E-PHPUNIT-FULL | 1,143 passed full regression |
| E-LIVE-HEADERS | HTTPS header inspection 21 Nov 2026 |
| E-LIVE-INTEGRITY | strict integrity table output |
| E-LIVE-AUDIT | audit-verify 105/105 |
| E-COMPOSER | composer audit advisories |
| E-ACCEPT-RO | `_phase4j_final_acceptance_ro.php` snapshot |
| E-OTP-PILOTS | Four-role live OTP confirmation |

## Appendix B — Package Versions (locked)

| Package | Version |
|---|---|
| laravel/framework | v13.32.0 |
| league/commonmark | 2.10.1 |
| symfony/http-foundation | v8.0.15 |
| symfony/yaml | v8.0.15 |

## Appendix C — Change Log

| Version | Date | Notes |
|---|---|---|
| 1.0 | 2026-11-21 | Initial STRIDE / controlled PT report |
| 1.1 | 2026-11-21 | Post-STRIDE local dependency remediation; header/checkpoint/account plans; retest evidence; production still on e2f3a36 |
| 1.2 | 2026-11-21 | Production deploy of dependency hardening + clinic description display cleanup at 3df7c9e; FIND-004/005/006 closed in production; Nginx/checkpoint still open |
| 1.3 | 2026-11-21 | FIND-007 checkpoint refreshed to seq 111 (lag 0); FIND-001/002/003 approved but blocked on interactive sudo |
| 1.4 | 2026-11-21 | FIND-001/002/003 verified live on production; AI Front Desk EN/FIL/Taglish symptom understanding improvements + 223-scenario offline eval |

---

## Appendix D — Post-STRIDE Hardening Update (v1.1 → v1.3)

### D.1 Dependency applicability and remediation

| Finding | Installed (baseline) | Affects MediGuide? | Remediation | Retest |
|---|---|---|---|---|
| FIND-004 commonmark | 2.10.1 | **Yes** (transitive via Laravel; GFM table DoS / raw HTML bypass if markdown rendered) | **2.10.2** deployed | `composer audit` clean (local + VPS) |
| FIND-005 Laravel concurrency | 13.32.0 | **Version affected; exploit path NOT present** (no `Concurrency::run` in app code) | **13.40.0** deployed | Suites PASS; VPS shows v13.40.0 |
| FIND-006 Symfony http-foundation/yaml | 8.0.15 | **Yes** (http-foundation runtime; yaml locked for require-dev) | http-foundation/serializer **7.4.23** production; yaml **7.4.23** locked (dev-only, not installed with `--no-dev`) | `composer audit` clean |
| Serializer residual | advisories on older line | Yes until upgraded | **7.4.23** deployed | Clear |

**Production deploy commit:** `3df7c9efc30d770cf3a9806ed0e96fd65d537a16`  
**Method:** `git merge --ff-only` + `composer install --no-dev --optimize-autoloader` (not `composer update`)  
**Before:** `composer audit` reported advisories on baseline packages.  
**After (local + VPS):** `composer audit` → **No security vulnerability advisories found.**

Focused clinic/AI/booking/security PHPUnit before commit: **395 passed**. Full regression: **1,145 passed**.

### D.1b Clinic description UI cleanup (same deploy)

| Check | Result |
|---|---|
| Four exclusion phrases in public display | **0 hits** (`AiClinicDescriptionKind::forPublicDisplay`) |
| Stored DB descriptions | Preserved (15 clinics still contain exclusion phrases in storage) |
| `updated_at` present | 16/16 clinics |
| Provenance / verification fields | Unchanged (display-only filter) |
| Admin edit fields / schedules / names | Unchanged |

### D.2 HTTP headers (FIND-001/002/003)

**Verified live** on `https://qmhchospital.com/login` (2026-11-20/21 UTC).

| Finding | Production evidence | Status |
|---|---|---|
| FIND-001 HSTS | `Strict-Transport-Security: max-age=15552000` (no includeSubDomains/preload) | **Resolved** |
| FIND-002 CSP | `Content-Security-Policy-Report-Only: …` present; **enforcement not enabled** | **Resolved as Report-Only** (enforcement still open) |
| FIND-003 Server banner | `Server: nginx` (version string removed) | **Resolved** |

CSP violation evidence: no dedicated report-uri collector is configured; violations (if any) are browser-local only. Do **not** mark CSP enforcement complete.

CSP compatibility note: Blade/Inertia/Vite/`'self'` Bootstrap OK; Google Fonts domains included; `app.blade.php` inline appearance bootstrap may still violate under Report-Only — enforce only after nonce/hash refactor.

### D.3 Audit checkpoint (FIND-007)

| Item | Value |
|---|---|
| Chain | **111/111 valid** |
| Previous checkpoint | seq **54** (lag 57) |
| Refreshed checkpoint | seq **111** (matches chain head) |
| Lag after refresh | **0** |
| Checkpoint protection | **verified** |
| Command | `php artisan mediguide:audit-checkpoint --execute` |
| Pre-refresh enc backup | `checkpoint_pre_refresh_20261120210542.jsonl.enc` |
| Post-refresh enc backup | `checkpoint_post_refresh_20261120210542.jsonl.enc` |
| Pass files | `.checkpoint_pass_pre_refresh_*` / `.checkpoint_pass_post_refresh_*` (store separately) |
| Historical events | Preserved (no key regeneration) |

### D.4 Account remediation (FIND-008)

Plan only: `docs/security/ops/account_email_remediation_plan.md`. Global OTP remains disabled. OTP pilots **33,35,36,37** unchanged. User 5 untouched. Residual operational risk: inaccessible / unverified account email addresses blocking fleet MFA.

### D.5 Retest status board

| Finding | Status |
|---|---|
| FIND-004 | **Resolved in production** at 3df7c9e (commonmark 2.10.2; audit clean) |
| FIND-005 | **Resolved in production** at 3df7c9e (Laravel 13.40.0; defense-in-depth) |
| FIND-006 | **Resolved in production** at 3df7c9e (http-foundation/serializer 7.4.23; yaml 7.4.23 locked for dev) |
| FIND-007 | **Resolved in production** — checkpoint seq 111 / lag 0 / protection verified |
| FIND-001 | **Resolved in production** — HSTS max-age=15552000 verified |
| FIND-002 | **Report-Only resolved** — enforcement still deferred |
| FIND-003 | **Resolved in production** — `Server: nginx` without version |
| FIND-008 | Plan only — **open** (inaccessible account emails) |

### D.6 Production verification snapshot (v1.3)

| Check | Result |
|---|---|
| Host / DB | srv2179431 / mediguide_demo |
| Git | 726d516cefc98e9c3595ef6c906f982545b0a8c2 |
| HOME / LOGIN | HTTP 200 |
| Role gates (unauth) | patient/staff/doctor/admin → 302 |
| Integrity strict | 11 appointments / 5 patients valid |
| Audit verify | valid; checkpoint protection verified; seq **111**; lag **0** |
| Auth flags | OTP enabled=true; OTP all roles=false; TOTP privileged=false; enforce email verification=false; require verified patient=true |
| Mail / AI | smtp.gmail.com password set; Anthropic configured (no live calls made) |
| config.php | 640 mediguide:www-data |
| Appointments / patients | unchanged (11 / 5) |
| PHP-FPM reload | **Pending interactive sudo** |
| Nginx change | **Pending interactive sudo** |

### D.7 Updated readiness conclusion

| Question | Verdict (v1.4) |
|---|---|
| Capstone demo security | **ACCEPTABLE**; deps + headers + checkpoint closed |
| Production dependency patch | **YES** |
| Audit checkpoint refresh | **YES** (seq 111) |
| Nginx header hardening | **YES** (HSTS + CSP-RO + tokens) |
| CSP enforcement | **NOT COMPLETE** (Report-Only only) |
| Mandatory fleet OTP | **NOT READY** (FIND-008 inaccessible inboxes) |
| AI Front Desk | Improved EN/FIL/Taglish overlays; **not clinically validated** |

### D.8 AI Front Desk symptom-understanding update (v1.4)

| Item | Result |
|---|---|
| Improvements | Informal Taglish spelling; expanded concern kinds (chest/vomiting/dental/urinary/sipon); tighter readiness for other/chest/vomiting; richer emergency phrases; concern-specific follow-ups; prompt Taglish/multi-symptom guidance |
| Offline eval | **223** synthetic scenarios; emergency recall **1.00**; kind agreement **≥0.80**; readiness agreement **≥0.75** |
| Clinical validation | **Not claimed** — requires qualified clinical review |
| Live Anthropic soak | **Not performed** (isolated/mocked only) |
