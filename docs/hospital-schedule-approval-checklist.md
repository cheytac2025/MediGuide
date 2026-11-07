# Hospital schedule approval checklist

**Hospital:** Queen Mary Help of Christians Hospital, Inc., Cardona, Rizal\
**System:** MediGuide online appointment preparation\
**Status:** Not approved. Every authorization field below is blank.

This checklist is for qualified hospital personnel. Completing a software dry run does not authorize online booking.

Poster hours in `docs/hospital-import-preview.md` are reference material. They are not confirmed operational schedules.

## One doctor per completed copy

| Field | Hospital entry |
| --- | --- |
| Doctor name | |
| Specialization | |
| Clinic | |
| Consultation days | |
| Start time | |
| End time | |
| Approved appointment duration (minutes) | |
| Appointment-only policy | Permitted online / Hospital confirmation required / Not permitted / Not applicable |
| Online booking permitted | Yes / No |
| Effective date | |
| Authorized hospital representative | |
| Approval date | |
| Signature | |
| Comments | |

## Software gates that stay closed until this form is approved

- No imported poster window is marked verified.
- No `DoctorSchedule` row is created from this checklist by itself.
- No appointment duration is written because the poster does not state one.
- Appointment-only doctors are not given public recurring slots.
- Existing appointments are not moved, deleted, or reassigned.

## Proposed activation sequence after a signed form exists

1. Confirm the signed doctor, specialization, clinic, day, hours, duration, and appointment-only policy match this form.
2. Run `php artisan mediguide:preview-schedule-activation` and review conflicts. The command does not write.
3. Resolve any existing schedule overlap or pending/confirmed appointment conflict before activation.
4. A later, separately approved write may create active `DoctorSchedule` rows only for fixed-hour doctors with an approved duration.
5. Leave appointment-only doctors without public slots until a separate booking rule is approved.

## Rollback if a later activation is reversed

- Deactivate the newly created schedule if it has no future pending or confirmed appointments.
- Do not delete historical appointments.
- Do not reassign appointments to another doctor.
- Do not change the seven-day booking window as part of rollback.
