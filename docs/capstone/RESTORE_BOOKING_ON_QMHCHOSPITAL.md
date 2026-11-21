# Restore AI-to-appointment booking on qmhchospital.com (Option A)

**Status:** Local code ready. **Not deployed. No production config/data changes applied.**

## Confirmed regression

| Fact | Value |
| --- | --- |
| Production commit | `cdff369` (AI booking handoff fix **is** present) |
| Site | https://qmhchospital.com |
| Database | `mediguide_demo` (shared production app DB) |
| `HOSPITAL_PROVISIONAL_LOCAL_BOOKING` | **false** |
| Active doctors | 18 |
| Active provisional schedule rows | 21 |
| Approved active schedules on active catalog doctors | effectively 1 (Dr. Robles id=4, Orthopedics) |
| Bookable now (flag off) | **1 / 18** |

**Why booking worked before:** Earlier local/dev runs had `HOSPITAL_PROVISIONAL_LOCAL_BOOKING=true` (or equivalent provisional visibility), so poster-derived demonstration schedules were offered and the AI→wizard handoff could complete.

**Exact regression:** Deployments kept the handoff code (`BookingIntent` / `PostAuthBookingRedirect` / Book Appointment UI) but production correctly left the global provisional flag **false**. Most catalog doctors only have `provisional_demonstration=1` schedules, so `AppointmentBookingService` hid those slots → AI cards showed unavailable / non-bookable except Dr. Robles.

Booking did **not** fail because of a missing handoff class. Enabling unrestricted public provisional booking is **not** the fix.

Appointment-only doctors (Eclipse, Enriquez, Saquitan-Epetia, Epetia) still have **zero** schedule rows until a reviewed coverage write adds provisional demonstration windows. Phase4J DoctorPilot also has no schedule (non-clinical pilot).

### Production active doctor inventory (RO, commit `cdff369`)

| ID | Doctor | Specialty | Schedules | Bookable (flag off) |
| --- | --- | --- | --- | --- |
| 4 | Dr. Robles | Ortho | 1 approved | yes |
| 5 | Dr. Acosta, Emil Rommel | Otolaryngologist | 1 provisional | no |
| 6 | Dr. Arada-Hocate, Maria Nickle | Pediatrician | 2 provisional | no |
| 7 | Dr. Balajadia, Ma. Imelda | IM-Cardiologist | 2 provisional | no |
| 8 | Dr. Cabreira, Alvin | Urologist | 1 provisional | no |
| 9 | Dr. Cadiente, Gilbert | Internal Medicine | 3 provisional | no |
| 10 | Dr. Chulvo-Chan, Ma. Niña Lourdes | Internal Medicine | 2 provisional | no |
| 11 | Dr. Combe, Reynaldo | Urologist | 1 provisional | no |
| 12 | Dr. Daquigan Jr., Angel | General Surgery | 2 provisional | no |
| 13 | Dr. Dela Cruz, Michael | IM-Nephrologist | 2 provisional | no |
| 14 | Dr. De Jesus, Mediatrix | OB-GYN & Integrative | 3 provisional | no |
| 15 | Dr. Dulay, Dennis | IM-Oncologist | 1 provisional | no |
| 16 | Dr. Eclipse Jr., Eulalio | General Surgery | **0** | no |
| 17 | Dr. Endaya, Hazel Anne | Pediatrician | 1 provisional | no |
| 18 | Dr. Enriquez, Tyron Vincent P. | Orthopedic Surgeon & Traumatology | **0** | no |
| 19 | Dr. Saquitan-Epetia, Angeli | Developmental/Behavioral Pediatrician | **0** | no |
| 20 | Dr. Epetia, Mar Christopher | Pediatric Rheumatologist | **0** | no |
| 21 | Phase4J DoctorPilot | Capstone pilot (non-clinical) | **0** | no |

## Safe restoration (without global provisional)

New controls (code in this branch):

```env
# Keep false on production
HOSPITAL_PROVISIONAL_LOCAL_BOOKING=false

# Capstone defense on the existing site
MEDIGUIDE_CAPSTONE_BOOKING=true
MEDIGUIDE_CAPSTONE_BOOKING_ACCOUNTS=patient.demo@mediguide.test
```

Behavior:

1. Provisional slots become **visible** for AI cards / calendars (labeled *Demonstration availability*).
2. Only allowlisted synthetic emails may **confirm** provisional bookings.
3. Public / non-allowlisted accounts still cannot complete provisional booking.
4. Existing approved appointments and patient records are untouched.
5. Visible banner: capstone demonstration notice.
6. Double-booking / conflict checks remain enforced.

## Production data changes still requiring separate approval

1. Deploy application code that includes capstone booking gates.
2. Set the two env flags above + rebuild secure config cache + reload PHP-FPM.
3. Create verified synthetic patient (and staff/doctor) accounts; put patient email on the allowlist.
4. Run `mediguide:ensure-demo-booking-coverage` **only after explicit approval** to add provisional windows for the 4 appointment-only doctors (does not alter approved schedules).
5. Smoke Ortho + Pediatrician + one appointment-only doctor end-to-end with the synthetic patient.

## Isolation guarantees

| Risk | Mitigation |
| --- | --- |
| Public unrestricted provisional booking | Global flag stays **false**; only allowlisted accounts can confirm |
| Fake “hospital-approved” hours | UI label *Demonstration availability*; banner required |
| Real patient data used for defense | Synthetic accounts only; no copy of real patients |
| Existing appointments altered | Coverage/command creates schedules only; no appointment mutation in restore |
| Demo subdomain confusion | Option A uses **qmhchospital.com** only |

## Rollback

1. Set `MEDIGUIDE_CAPSTONE_BOOKING=false` (or clear allowlist).
2. Secure config cache + PHP-FPM reload.
3. Optional: deactivate provisional windows added solely for appointment-only doctors.
4. Application rollback to prior SHA if needed.

## Blockers before production enablement

- Operator approval for env flags on `mediguide_demo`
- Operator approval for schedule coverage write (4 appointment-only doctors)
- Synthetic account creation + allowlist emails
- Deploy of this code commit to `/var/www/mediguide`
- Interactive sudo for config cache / FPM reload
