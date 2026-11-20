# FIND-008 — Existing Account Email Remediation Plan

**Status:** Plan only. No account modifications executed in this hardening batch.

## Inventory snapshot (production, last verified)

| Metric | Value |
|---|---|
| Active users | 35 |
| Active Gmail | 8 |
| Active non-Gmail | 27 |
| Active unverified | 26 |
| Inaccessible-domain heuristic (`.test` etc.) | 4 |
| OTP-ready (`email_otp_enabled` + verified) | 4 (pilots 33, 35, 36, 37) |
| Global mandatory OTP | **disabled** (keep disabled) |

## Risk

Enabling `AUTH_REQUIRE_EMAIL_OTP_ALL_ROLES=true` would lock out unverified / inaccessible-inbox accounts on password login.

## Administrator-controlled remediation (recommended)

1. Export active users by role (id, role, email domain, verified?, otp_optin?) — read-only.
2. Classify:
   - **A:** Verified Gmail / reachable inbox
   - **B:** Unverified but reachable domain → send claim/verification invitation
   - **C:** `.test` / unreachable → replace via controlled claim invitation to a real inbox (admin-approved)
3. Use existing `AccountInvitationService` claim/activation flows only.
4. Preserve roles, departments, doctor/clinic links, appointments, audit history.
5. Do **not** auto-set `email_verified_at`.
6. Do **not** reset passwords.
7. Keep pilots 33/35/36/37 OTP-enabled until demo cleanup approval.
8. Re-inventory until Category B/C are remediated; only then consider global OTP.

## Suggested approval phrases (future)

```text
APPROVED run MediGuide active-account email inventory export
APPROVED send MediGuide claim invitations for approved remediation cohort N
```

## Explicit non-goals now

- No mandatory global OTP
- No bulk email sends
- No password resets
- No role/department changes
