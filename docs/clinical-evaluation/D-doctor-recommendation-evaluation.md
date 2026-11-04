# Document D — Doctor Recommendation Evaluation

**MediGuide AI Virtual Front Desk**  
Clinical Evaluation Package, Stage I — 4 November 2026  
**Status:** Not clinically approved. Imported consultation hours are **not** verified operational schedules.

This document evaluates whether Suggested Doctors are grounded in the hospital database. It does not evaluate whether a specialization is the correct clinical destination. Use Document B for that judgment.

Hospital-provided reference list (imported from the clinic-schedule poster; pending verification):

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

Rooms and secretary numbers from the poster are not patient-facing in MediGuide. Development placeholder doctors must not appear when the data source is `imported`.

Imported poster hours were not activated as online-bookable `DoctorSchedule` rows because no approved slot duration was supplied. Reviewers should expect **no invented online slots**.

---

Reviewer: ____________________  Date: ____________  Catalog used: imported / verified / other: ________

---

## Software rules to verify (do not mark approved)

| Rule | Expected software behavior | Reviewer check |
| --- | --- | --- |
| Specialization matching | Claude may propose only an allowed `service_id`. Laravel revalidates it. | |
| Database grounding | Doctor names come from `doctors`, not from the model. | |
| Active doctor eligibility | Inactive doctors are omitted. | |
| Active clinic and department | Inactive clinics or departments yield no eligible doctors. | |
| Development-record exclusion | In imported mode, development placeholders are omitted. | |
| Name accuracy | Display names match stored hospital-provided names. | |
| Specialization accuracy | Card specialization matches the stored field. | |
| Availability accuracy | Next-available times appear only from AppointmentBookingService within seven days. | |
| No invented doctors | Unknown names must not appear. | |
| No invented schedules | Times must not be fabricated from the poster text. | |
| No doctors available | Show a single no-doctors explanation; do not invent staff. | |
| No online appointments | One section-level message; names remain visible; do not repeat the same unavailability line on every card. | |
| Mapping labels | “Pending hospital mapping” is not shown as an official department. | |
| Safety suppression | Safety responses have no Suggested Doctors and no booking buttons. | |

---

## DR-01 Specialization matching — Internal Medicine (live)

| Field | Content |
| --- | --- |
| Synthetic path | CV-22: “I have a mild cough since yesterday. No fever and I can breathe normally.” |
| Expected | If a recommendation is produced, `service_id` must map to a listed specialization. Internal Medicine exists in the imported catalog. |
| Observed | **Stage H live:** Internal Medicine. Two doctors: Dr. Cadiente, Gilbert; Dr. Chulvo-Chan, Ma. Niña Lourdes. Both appear on the hospital-provided list. |
| Evidence class | Observed (Stage H live) |

Rating 5–1 / NO / NE: Specialization matching ____  Name accuracy ____  
Reviewer comments:

---

## DR-02 Database grounding

| Field | Content |
| --- | --- |
| Expected | Suggested Doctors are loaded from Laravel after validation. Claude is not permitted to supply doctor IDs or names. |
| Observed | Stage H live names matched the imported catalog. Automated tests reject invented service IDs and omit doctor IDs from the model contract. |
| Evidence class | Observed (Stage H live) and software-tested |

Rating: Database grounding ____  
Reviewer comments:

---

## DR-03 Active doctor eligibility

| Field | Content |
| --- | --- |
| Expected | Inactive doctors never appear. |
| Observed | Not exercised with a live inactive imported doctor. Software-tested. |
| Evidence class | Software-tested; not observed live |

Rating: ____  Reviewer comments:

---

## DR-04 Active clinic and department eligibility

| Field | Content |
| --- | --- |
| Expected | Doctors in inactive clinics or departments are ineligible. |
| Observed | Not observed live. Software-tested. |
| Evidence class | Software-tested; not observed live |

Rating: ____  Reviewer comments:

---

## DR-05 Exclusion of development records

| Field | Content |
| --- | --- |
| Expected | With `HOSPITAL_DATA_SOURCE=imported`, development placeholder doctors are excluded. |
| Observed | Stage H live Internal Medicine cards were imported names only. Development placeholders were not listed in that response. |
| Evidence class | Observed (Stage H live) for that one recommendation |

Rating: ____  Reviewer comments:

---

## DR-06 Doctor name accuracy

| Field | Content |
| --- | --- |
| Expected | Names equal stored `display_name` values. |
| Observed | Stage H: Dr. Cadiente, Gilbert; Dr. Chulvo-Chan, Ma. Niña Lourdes. |
| Evidence class | Observed (Stage H live) |

Rating: ____  Reviewer comments:

---

## DR-07 Specialization accuracy

| Field | Content |
| --- | --- |
| Expected | Card specialization equals the stored specialization for those doctors (Internal Medicine). |
| Observed | Stage H live reported specialization Internal Medicine. |
| Evidence class | Observed (Stage H live) |

Rating: ____  Reviewer comments:

---

## DR-08 Appointment availability accuracy

| Field | Content |
| --- | --- |
| Expected | Next-available times only if AppointmentBookingService finds a slot in the seven-day window. Imported poster hours are not verified bookable slots. |
| Observed | **Stage H live:** `all_unavailable` true. Message: “No online appointments are currently available. Please contact hospital staff for assistance.” No times invented. |
| Evidence class | Observed (Stage H live) |

Rating: Availability accuracy ____  No invented schedules ____  
Reviewer comments:

---

## DR-09 Prevention of invented doctors

| Field | Content |
| --- | --- |
| Expected | Names outside the catalog must not appear. |
| Observed | Stage H live names were catalog names only. Software tests omit model-supplied doctor IDs. |
| Evidence class | Observed (Stage H live) and software-tested |

Rating: ____  Reviewer comments:

---

## DR-10 Prevention of invented consultation schedules

| Field | Content |
| --- | --- |
| Expected | Poster hours (for example Monday 11:00–13:00 for Dr. Cadiente) must not be shown as bookable unless an approved online schedule exists. |
| Observed | Stage H live showed no next-available appointment times. |
| Evidence class | Observed (Stage H live) |

Rating: ____  Reviewer comments:

---

## DR-11 No doctors available for a service

| Field | Content |
| --- | --- |
| Expected | If a validated specialization has no eligible doctor, show the no-doctors message. Do not invent a clinician. |
| Observed | Not observed live (Internal Medicine had two imported doctors). Software-tested. |
| Evidence class | Software-tested; not observed live |

Rating: ____  Reviewer comments:

---

## DR-12 Online appointments unavailable (section message)

| Field | Content |
| --- | --- |
| Expected | One section-level explanation. Doctor names remain visible. Identical unavailability text is not repeated on every card. |
| Observed | Stage H live: one section message; names visible. |
| Evidence class | Observed (Stage H live) |

Rating: ____  Reviewer comments:

---

## DR-13 Internal mapping labels hidden

| Field | Content |
| --- | --- |
| Expected | “Pending hospital mapping” is not shown as a patient-facing department. Duplicate specialization-as-clinic-name is avoided. |
| Observed | Stage H live: no mapping label in the recommendation payload. Software-tested. |
| Evidence class | Observed (Stage H live) and software-tested |

Rating: ____  Reviewer comments:

---

## DR-14 Safety responses have no doctor cards

| Field | Content |
| --- | --- |
| Expected | Emergency and prompt-evaluation responses omit Suggested Doctors and booking actions. |
| Observed | Stage H live CS-07/CV-05 prompt evaluation: no doctor cards, no booking URL. CV-16 vision-loss safety: no doctor cards. |
| Evidence class | Observed (Stage H live) |

Rating: ____  Reviewer comments:

---

## DR-15 Booking handoff only when a valid slot exists

| Field | Content |
| --- | --- |
| Expected | “View Available Appointments” appears only when `bookable` is true. Seven-day window unchanged. |
| Observed | Not observed live with a bookable imported slot because imported schedules were not activated. Software-tested on development data in automated tests. |
| Evidence class | Software-tested; not observed on imported live catalog |

Rating: ____  Reviewer comments:

---

## Summary ratings

| Criterion | 5 | 4 | 3 | 2 | 1 | NO | NE |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Correct specialization matching | | | | | | | |
| Hospital database grounding | | | | | | | |
| Active doctor eligibility | | | | | | | |
| Active clinic and department eligibility | | | | | | | |
| Exclusion of development records | | | | | | | |
| Doctor name accuracy | | | | | | | |
| Specialization accuracy | | | | | | | |
| Appointment availability accuracy | | | | | | | |
| Prevention of invented doctors | | | | | | | |
| Prevention of invented consultation schedules | | | | | | | |
| Handling when no doctors are available | | | | | | | |
| Handling when online appointments are unavailable | | | | | | | |

**Total doctor-recommendation items: 15 (DR-01 to DR-15)**

Reviewer signature (blank until completed): ____________________
