# Full-catalog demonstration booking coverage

Isolated capstone path so **every eligible active doctor** can be recommended and booked through the AI Front Desk without enabling provisional booking on public production.

## Isolation rules

| Control | Requirement |
| --- | --- |
| Database | Disposable copy only (e.g. `mediguide_showcase_capstone` or SQLite). Never `mediguide_demo`. |
| Flag | `HOSPITAL_PROVISIONAL_LOCAL_BOOKING=true` only on that isolated process |
| Host | Local loopback MySQL (`127.0.0.1`) or PHPUnit SQLite |
| Schedules written | `provisional_demonstration=true` only |
| Approved schedules | Never modified |
| Public VPS | Keep provisional booking **false**; do not deploy demo windows to production without separate approval |

## Prepare an isolated database

Verified local full-catalog database: **`mediguide_capstone_full_demo`**
(created 2026-11-21: migrate → RoleSeeder → hospital import → ensure coverage → **16/16 bookable**).

1. Create a disposable MySQL database (do not use `mediguide_demo`).
2. Point a local process at that database with `HOSPITAL_PROVISIONAL_LOCAL_BOOKING=true` and `HOSPITAL_DATA_SOURCE=imported`.
3. Migrate + seed roles, then import poster schedules (30-minute slots).
4. Ensure coverage:

```bash
php artisan mediguide:ensure-demo-booking-coverage \
  --confirm-local-demonstration \
  --confirm-database=mediguide_capstone_full_demo
```

Dry-run inventory:

```bash
php artisan mediguide:ensure-demo-booking-coverage \
  --confirm-local-demonstration \
  --confirm-database=mediguide_capstone_full_demo \
  --dry-run
```

Success means `bookable_after == eligible_active` and zero `UNBOOKABLE` lines.

## Expected visitor UX

1. Symptom → Claude specialty recommendation grounded in catalog.
2. Matching doctors listed; bookable cards show **Book Appointment**.
3. Demonstration slots are labeled `Demonstration availability: …`.
4. Login / OTP / email verification preserve booking intent.
5. Wizard preselects clinic + doctor and offers real computed slots.
6. Confirm → pending appointment → staff confirm → doctor consult → notifications.

Emergency / safety responses still offer **no** routine booking.

## Automated proof

`tests/Feature/Demo/DemoBookingCoverageTest.php` covers:

- Guard refusals (confirmation, provisional flag, blocked DB names)
- Full Queen Mary catalog (16 active) reaches 100% eligible bookable coverage
- Appointment-only doctors receive provisional windows only
- Specialty-grounded AI cards
- Per-doctor handoff + booking
- Double-booking prevention
- Approved schedules left untouched
