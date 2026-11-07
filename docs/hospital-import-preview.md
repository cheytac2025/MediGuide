# Queen Mary clinic-schedule import preview

This preview is a dry-run plan. It was not written to the existing `mediguide` database.

Stage L activation readiness is documented separately in `docs/hospital-schedule-activation.md`. That document does not replace the poster hours below.

Source: Queen Mary Help of Christians Hospital, Inc., Cardona, Rizal — Clinic Schedules poster supplied on 4 Nov 2026. Both uploaded images were the same page. The poster is reference material, not proof that every doctor or hour is currently active.

Verification status after any approved import: **pending hospital verification**. Records are marked `imported`, not `verified`.

Secretary names and numbers were visible on the poster and are **not** included in this import.

## Proposed department

| Name | Mapping | Status |
| --- | --- | --- |
| Pending hospital mapping | **Inferred.** The poster lists specializations, not official department names. | Requires hospital confirmation |

## Proposed clinics

Each clinic name is copied from a source specialization. These are not official hospital clinic names.

| Clinic name (from specialization) | Mapping |
| --- | --- |
| Otolaryngologist (Ears, Nose, Throat) | Inferred from specialization |
| Pediatrician | Inferred from specialization |
| IM-Cardiologist | Inferred from specialization; not merged with Internal Medicine |
| Urologist | Inferred from specialization |
| Internal Medicine | Inferred from specialization |
| General Surgery | Inferred from specialization |
| IM-Nephrologist | Inferred from specialization; not merged with Internal Medicine |
| Obstetrician Gynecologist & Integrative | Inferred; see ambiguous entries |
| IM-Oncologist | Inferred from specialization; not merged with Internal Medicine |
| Orthopedic Surgeon & Traumatology (Fracture, Bone & Joint Disease) | Inferred from specialization |
| Developmental and Behavioral Pediatrician | Inferred; not merged with Pediatrician |
| Pediatric Rheumatologist | Inferred; not merged with Pediatrician |

Rooms from the poster (108, 100, 106b, 101, 109, 103, 107, OPD, 105) cannot be stored in the current clinic/doctor schema.

## Proposed doctors

Imported without login accounts, contact numbers, or passwords. Proposed operational status: Active. Verification: pending.

1. Dr. Acosta, Emil Rommel — Otolaryngologist (Ears, Nose, Throat)
2. Dr. Arada-Hocate, Maria Nickle — Pediatrician
3. Dr. Balajadia, Ma. Imelda — IM-Cardiologist
4. Dr. Cabreira, Alvin — Urologist
5. Dr. Cadiente, Gilbert — Internal Medicine
6. Dr. Chulvo-Chan, Ma. Niña Lourdes — Internal Medicine
7. Dr. Combe, Reynaldo — Urologist
8. Dr. Daquigan Jr., Angel — General Surgery
9. Dr. Dela Cruz, Michael — IM-Nephrologist
10. Dr. De Jesus, Mediatrix — Obstetrician Gynecologist & Integrative
11. Dr. Dulay, Dennis — IM-Oncologist
12. Dr. Eclipse Jr., Eulalio — General Surgery
13. Dr. Endaya, Hazel Anne — Pediatrician
14. Dr. Enriquez, Tyron Vincent P. — Orthopedic Surgeon & Traumatology (Fracture, Bone & Joint Disease)
15. Dr. Saquitan-Epetia, Angeli — Developmental and Behavioral Pediatrician
16. Dr. Epetia, Mar Christopher — Pediatric Rheumatologist

## Proposed schedules (source hours)

Fixed hours are listed below. They become bookable `DoctorSchedule` rows **only** if an approved `--bookable-slot-minutes` value is supplied. The poster does not state slot length. The schedule model requires `slot_duration`.

| Doctor | Day | Start | End | Room (source only) |
| --- | --- | --- | --- | --- |
| Dr. Acosta, Emil Rommel | Thursday | 15:00 | 16:00 | 108 |
| Dr. Arada-Hocate, Maria Nickle | Tuesday | 15:00 | 17:00 | 100 |
| Dr. Arada-Hocate, Maria Nickle | Thursday | 14:00 | 17:00 | 100 |
| Dr. Balajadia, Ma. Imelda | Monday | 12:00 | 15:00 | 106b |
| Dr. Balajadia, Ma. Imelda | Friday | 12:00 | 15:00 | 106b |
| Dr. Cabreira, Alvin | Thursday | 14:00 | 16:00 | 101 |
| Dr. Cadiente, Gilbert | Monday | 11:00 | 13:00 | 109 |
| Dr. Cadiente, Gilbert | Wednesday | 11:00 | 13:00 | 109 |
| Dr. Cadiente, Gilbert | Friday | 11:00 | 13:00 | 109 |
| Dr. Chulvo-Chan, Ma. Niña Lourdes | Tuesday | 09:00 | 12:00 | 100 |
| Dr. Chulvo-Chan, Ma. Niña Lourdes | Saturday | 14:00 | 17:00 | 100 |
| Dr. Combe, Reynaldo | Monday | 15:00 | 17:00 | 103 |
| Dr. Daquigan Jr., Angel | Thursday | 10:00 | 12:00 | 101 |
| Dr. Daquigan Jr., Angel | Saturday | 10:00 | 12:00 | 101 |
| Dr. Dela Cruz, Michael | Wednesday | 13:00 | 16:00 | 107 |
| Dr. Dela Cruz, Michael | Saturday | 13:00 | 16:00 | 107 |
| Dr. De Jesus, Mediatrix | Wednesday | 10:00 | 12:00 | OPD |
| Dr. De Jesus, Mediatrix | Thursday | 10:00 | 12:00 | OPD |
| Dr. De Jesus, Mediatrix | Friday | 10:00 | 12:00 | OPD |
| Dr. Dulay, Dennis | Wednesday | 14:00 | 16:00 | 103 |
| Dr. Endaya, Hazel Anne | Saturday | 14:00 | 17:00 | 101 |

Source notes that cannot be stored: Arada-Hocate hours starting 9 Oct 2025; Chulvo-Chan hours starting 18 Oct 2025. The schedule model has no effective-from date.

## Appointment-only restrictions

These doctors are imported as identities only. Listed hours are **not** converted into bookable slots until a booking rule is approved.

| Doctor | Restriction | Listed hours (not imported as slots) |
| --- | --- | --- |
| Dr. Eclipse Jr., Eulalio | by appointment | Tuesday 10:00–12:00 |
| Dr. Enriquez, Tyron Vincent P. | by appointment | Wednesday and Saturday 13:00–16:00 |
| Dr. Saquitan-Epetia, Angeli | strictly by appointment | Friday, no hours listed |
| Dr. Epetia, Mar Christopher | strictly by appointment | Friday, no hours listed |

## Ambiguous entries

- Dr. De Jesus, Mediatrix: Wednesday labeled OB, Thursday and Friday labeled Integrative Medicine, same 10:00–12:00 hours. One specialization string is stored because the doctor model has a single specialization field.
- Dr. Eclipse Jr., Eulalio: hours exist but are labeled by appointment.
- Dr. Enriquez, Tyron Vincent P.: hours exist but are labeled by appointment.

## Development placeholder cleanup (optional flag)

`--deactivate-development` deactivates unused development names after the hospital records are imported. It does not delete rows and does not reassign appointments.

| Record | Proposed action |
| --- | --- |
| Test Doctor 1, Test Doctor 2, Test Doctor 3 | Deactivate if still active |
| Development Clinic A, B, C | Deactivate when no active doctors remain |
| Development Department A, B, C | Deactivate when no active clinics remain |

Historical appointments keep their original doctor, clinic, and schedule identifiers.

## Command

```bash
php artisan mediguide:import-hospital-schedules
php artisan mediguide:import-hospital-schedules --execute --bookable-slot-minutes=30 --deactivate-development
```

Dry-run is the default. Do not run `--execute` against the existing `mediguide` database until this plan is approved.

After an approved import, set `HOSPITAL_DATA_SOURCE=imported` only when that is the intended marker. Do not set `verified` until authorized hospital personnel confirm the records.
