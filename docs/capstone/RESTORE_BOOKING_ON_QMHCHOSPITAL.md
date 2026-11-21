# Restore AI-to-appointment booking on qmhchospital.com (Option A)

**Status:** Safety-corrected code ready. Deploy application code only after review. **Do not enable capstone booking or write demonstration schedules until a separate approval names the exact write scope.**

## Confirmed regression

| Fact | Value |
| --- | --- |
| Site | https://qmhchospital.com |
| Database | `mediguide_demo` (shared production app DB) |
| `HOSPITAL_PROVISIONAL_LOCAL_BOOKING` | **false** (must stay false) |
| Active doctors | **18** |
| Imported catalog doctors | **16** |
| Extra production doctors | Dr. Robles (Ortho, approved schedule); Phase4J DoctorPilot (non-clinical) |
| Bookable with flag off (public) | **1 / 18** (Dr. Robles) |

**Why booking worked before:** Provisional demonstration schedules were visible (local provisional flag or equivalent).

**Exact regression:** Production correctly keeps the global provisional flag off. Most catalog doctors only have `provisional_demonstration=1` schedules, so slots stay hidden from ordinary users. AI handoff code is present; catalog coverage is the ops gap.

## 16 vs 18 doctor discrepancy (not silent)

| Source | Count | Notes |
| --- | --- | --- |
| Queen Mary imported catalog | 16 | Poster-derived specialties |
| Dr. Robles | +1 | Development Ortho doctor with **approved** schedule — **eligible** |
| Phase4J DoctorPilot | +1 | Explicit exclusion: `non_clinical_pilot` |
| **Eligible demonstration doctors** | **17** | All active except Phase4J |

## Safe restoration (account-scoped visibility + confirm)

```env
# Keep false on production
HOSPITAL_PROVISIONAL_LOCAL_BOOKING=false

# Capstone defense — enable only after separate approval
MEDIGUIDE_CAPSTONE_BOOKING=true
MEDIGUIDE_CAPSTONE_BOOKING_USER_IDS=<synthetic_patient_user_id>
# Optional secondary fallback when user IDs are empty (local tests only)
MEDIGUIDE_CAPSTONE_BOOKING_ACCOUNTS=patient.demo@mediguide.test
```

Behavior:

1. Guests and ordinary patients **never** see provisional slots as bookable hospital appointments.
2. Approved schedules (e.g. Dr. Robles) remain visible to ordinary patients.
3. Only authenticated, **active**, **email-verified** synthetic **patient** accounts on the **user-ID allowlist** may see and confirm provisional demonstration slots.
4. UI labels *Demonstration availability*; banner only for authorized synthetic patients.
5. Conflict / double-booking, CSRF, OTP, RBAC, integrity, and audit controls unchanged.

## Production data changes still requiring separate approval

1. Deploy application code that includes the tightened capstone gates.
2. Create verified synthetic patient; set `MEDIGUIDE_CAPSTONE_BOOKING_USER_IDS` to that user id.
3. Set `MEDIGUIDE_CAPSTONE_BOOKING=true` + rebuild secure config cache + reload PHP-FPM.
4. Run `mediguide:ensure-demo-booking-coverage` **only after explicit write approval** for doctors missing provisional windows (does not alter approved schedules; refuses blocked DB names unless operator overrides isolation policy for Option A).
5. Smoke Ortho + Pediatrician + one appointment-only doctor with the synthetic patient.

**Note:** Coverage writes currently require an isolated process (`HOSPITAL_PROVISIONAL_LOCAL_BOOKING` + loopback/demo instance). On shared `mediguide_demo`, schedule coverage needs a reviewed one-shot operator procedure — do not invent that path without approval.

## Isolation guarantees

| Risk | Mitigation |
| --- | --- |
| Public provisional visibility | Visibility gated by same allowlisted patient identity as confirm |
| Email-only spoofing | Prefer stable user IDs; email is fallback only when ID list empty |
| Unverified / inactive accounts | Rejected even if ID is listed |
| Fake “hospital-approved” hours | *Demonstration availability* label + banner |
| Phase4J treated as clinical | Explicit `non_clinical_pilot` exclusion in inventory |
| Existing appointments altered | Coverage creates schedules only; no appointment mutation in restore |

## Rollback

1. Set `MEDIGUIDE_CAPSTONE_BOOKING=false` (or clear user IDs).
2. Secure config cache + PHP-FPM reload.
3. Optional: deactivate provisional windows added solely for coverage.
4. Application rollback to prior SHA if needed.

## Blockers before production enablement

- Operator approval for env flags on `mediguide_demo`
- Operator approval for schedule coverage write scope + isolation procedure
- Synthetic patient creation + user ID allowlist
- Deploy of reviewed safety-corrected commit
- Interactive sudo for config cache / FPM reload
