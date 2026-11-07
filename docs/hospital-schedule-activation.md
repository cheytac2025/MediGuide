# Hospital schedule activation readiness

**Date:** 5 November 2026\
**Status:** Local demonstration only. The 21 fixed-hour windows may be stored as provisional 30-minute schedules. They are not hospital-verified. Booking them requires `HOSPITAL_PROVISIONAL_LOCAL_BOOKING=true` on the local `mediguide` database at `127.0.0.1`. Copying the database does not enable them elsewhere.

This note describes the scheduling architecture that already exists and the read-only activation preview added in Stage L. It does not replace `docs/hospital-import-preview.md`.

## Existing scheduling architecture

`doctor_schedules` stores one recurring weekly window per row:

- `doctor_id`
- `day_of_week`
- `start_time`
- `end_time`
- `slot_duration` in minutes, required and greater than zero
- `status` of `active` or `inactive`

Different doctors, and different windows for the same doctor, can use different `slot_duration` values. The poster does not define a duration, so Stage L does not choose 15, 20, 30, or 60 minutes and does not write a duration.

`AppointmentBookingService` builds slots by stepping `slot_duration` from the window start until another full slot would pass the end. Pending and confirmed appointments block a doctor for the overlapping time. Cancelled, rejected, and completed appointments do not. Booking runs in a transaction, locks the patient, doctor, and schedule, and rejects overlaps. The booking window remains `config('appointments.booking_window_days')`, default 7, in `config('app.timezone')`, default `Asia/Manila`. Expired times on the current hospital date are excluded.

An active schedule cannot overlap another active schedule for the same doctor and day. An identical doctor, day, start, and end is rejected by a unique index. Deactivating or editing a schedule is refused when a future pending or confirmed appointment would no longer fit. Historical appointments stay on their original schedule id.

Inactive doctors, clinics, or departments produce no public slots. Hospital Staff may create and update schedules only for doctors in their assigned departments. IT Administrators manage departments, clinics, doctors, and users; they do not have the staff schedule routes. Doctors can view their own schedule. No new permission was granted.

AI Suggested Doctors still asks Claude only for a validated specialization. Laravel loads eligible doctors and calls `AppointmentBookingService::nextAvailableSlot()`. With no active schedule, the existing unavailable message is shown. No next-available time is invented.

## Poster hours versus confirmed schedules

| Class | Meaning in MediGuide now |
| --- | --- |
| Hospital-provided poster information | Hours and appointment-only notes in the catalog and `docs/hospital-import-preview.md` |
| Hospital-confirmed operational schedules | None. No signed checklist exists |
| Proposed online-bookable schedules | Not created. A preview can describe them only |
| Existing active schedules | Development or manually managed `DoctorSchedule` rows only. Imported poster hours were not activated |

The Stage L hour summary matches the existing catalog and import preview. No source was overwritten.

Appointment-only poster notes are not a database column. The schema cannot store hospital confirmation, manual approval distinct from pending appointments, a public consultation window that is not bookable, an effective date, or a room. Doctors with no fixed hours simply have no `DoctorSchedule` rows. A new appointment-only workflow is proposed below and was not implemented.

## Appointment-only proposal (not implemented)

Keep these doctors without public slots until a separate approval:

- Dr. Eclipse Jr., Eulalio — Tuesday 10:00–12:00, by appointment
- Dr. Enriquez, Tyron Vincent P. — Wednesday and Saturday 13:00–16:00, by appointment
- Dr. Saquitan-Epetia, Angeli — Friday, strictly by appointment, no hours
- Dr. Epetia, Mar Christopher — Friday, strictly by appointment, no hours

A future workflow would need an approved rule for whether those hours may ever become public slots, whether staff must confirm each request, and where that restriction is stored. Do not treat the listed hours as ordinary recurring slots before that approval.

## Dry-run preview

`php artisan mediguide:preview-schedule-activation`

The command and `HospitalScheduleActivationPreview` only read. They do not create schedules, change appointments, or mark records verified. `--hypothetical-minutes` is a display value only. It is not saved, and it does not by itself make a doctor online-bookable.

Each preview row includes the doctor, specialization, clinic when a matching record exists, day, hours, proposed duration, schedule conflicts, pending or confirmed appointment conflicts, whether activation would make the window online-bookable, and missing approvals.

Online booking stays closed unless a review explicitly supplies identity confirmation, a positive duration that fits the window, operational validity, hospital authorization, an active doctor, clinic, and department, a fixed-hour poster window, and no conflicts. Appointment-only rows stay closed even if those flags are supplied to the preview.

## Activation workflow

Use `docs/hospital-schedule-approval-checklist.md`. Leave it blank until a hospital representative signs it.

After a signed form exists, review a fresh dry run, resolve conflicts, and only then consider a separate write of active `DoctorSchedule` rows for fixed-hour doctors. Stage L does not perform that write.

## Rollback

If a later activation must be reversed, deactivate the new schedule when it has no future pending or confirmed appointments. Do not delete or reassign historical appointments. Do not change the seven-day window, overlap protection, IAM, encryption, HMAC, or the audit trail as part of that rollback.

## Remaining gaps

- No appointment-only or effective-date columns.
- No stored hospital approval or signature.
- Poster rooms cannot be stored on the current clinic or doctor tables.
- Dr. De Jesus still has one specialization string for OB and Integrative labels.
- Arada-Hocate and Chulvo-Chan source effective dates cannot be stored.
- Imported doctors still have no login accounts, by design.
