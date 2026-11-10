# MediGuide capstone demonstration

Isolated walkthrough of the AI Virtual Front Desk and appointment booking. This is a demonstration script, not clinical validation and not hospital approval of the schedules.

Queen Mary Help of Christians Hospital, Inc., Cardona, Rizal, supplied the poster used as the imported catalog. The hospital manages operational schedules after turnover. The 30-minute slots below are provisional demonstration slots. They are not a hospital-approved appointment duration.

## Prerequisites

- Use a disposable SQLite database, or another isolated copy. Do not point the demonstration at the live hospital database.
- Set `APP_ENV=testing` for that process so provisional demonstration slots can be booked. Do not weaken this rule for the live application.
- Set `HOSPITAL_DATA_SOURCE=imported`.
- Set `HOSPITAL_PROVISIONAL_LOCAL_BOOKING=true` only on that isolated process.
- Keep `AI_CLINICAL_KNOWLEDGE` and `AI_SAFETY_EVIDENCE_SCHEMA` unset. Both default off.
- Replace the Anthropic key in that process with a non-working placeholder when the demonstration uses mocked guidance. Do not call the live Claude API unless that call was separately approved.
- Timezone is Asia/Manila. The booking window is seven days, with today as day 0.
- Import the poster catalog with a 30-minute slot duration. Do not edit the live hospital records to prepare the demo.

Expected import result: 16 doctors, 21 provisional schedule windows, and 4 appointment-only doctors with no ordinary public schedule. Twelve of the sixteen doctors have published fixed windows. Clinic and department names such as the specialization label and “Pending hospital mapping” are temporary mappings, not official hospital names.

## Accounts and roles

Create these accounts inside the isolated database. Do not print passwords on slides, and do not reuse a live hospital account.

| Role | Isolated identity | Scope |
| --- | --- | --- |
| Patient | Synthetic patient | Own appointments only |
| Hospital Staff | Synthetic staff assigned to Pending hospital mapping | Appointments and schedules in that department |
| Hospital Staff | Second synthetic staff assigned to a different department | Must not see the first department’s appointment |

The development staff seeder account belongs to the development data source. It is not the imported-hospital demonstration account.

## Clinic-first navigation

A routine recommendation leads with the validated service label, then a server-written note, then Suggested Doctors. The note says the result is a navigation suggestion and not a diagnosis. For imported poster records, the same note says the name is a listed hospital record and not a verified physical clinic location.

“Pending hospital mapping” is an internal department label. The visitor card must not present it as a place to go. A clinic name that repeats the specialization is a temporary grouping, not a room assignment.

A Hospital Verified clinic with a distinct name can be labeled as a verified destination. The imported poster rows are not Hospital Verified, so the defense catalog stays on the listed-record wording.

## Prompt evaluation, emergency, and uncertain concerns

These responses keep the existing urgency wording. They add a separate assistance section and do not show booking controls.

- Prompt medical evaluation: keep the prompt-evaluation message. The assistance section tells the visitor to ask hospital staff where to go for prompt in-person assessment. It does not name an outpatient clinic and does not offer a routine appointment.
- Emergency: keep the immediate emergency message. If the database has no Hospital Verified emergency destination, the section tells the visitor to seek immediate emergency assistance. Do not add a follow-up question before that instruction.
- Uncertain: say the information is not enough to choose a clinic, and give the same kind of staff-assistance instruction. Do not invent a clinic.

Filipino and Taglish visitors receive the Filipino assistance text. English visitors receive the English text. There is no verified staff-contact channel on this screen, so the old disabled “Ask Hospital Staff” button is not shown.

## Synthetic conversation

Use a synthetic concern. A deterministic defense should inject mocked Claude responses in the browser and label them as mocked.

1. The patient opens the AI Front Desk. If the disclaimer has not been acknowledged in that session, it is required before the desk opens. The patient checks the acknowledgement and continues.
2. The patient submits one synthetic concern, for example a short Filipino headache sentence.
3. The mocked response asks one relevant follow-up. For a recognized headache, the application follow-up is “Kailan po nagsimula ang sakit ng ulo?” when a generic safety payload is replaced by that clarification.
4. The patient answers that question. The next turn still refers to the opening concern.
5. A mocked recommendation returns the eligible Pediatrician service ID. Laravel rejects any other service ID.
6. The card heading is the listed service, followed by the non-diagnostic note, then the eligible imported doctors and the calculated next slot.

Do not describe a mocked reply as a live Claude result. Do not describe schema acceptance as a clinical judgment.

## Expected doctor recommendation

For the Pediatrician service, the database can show:

- Dr. Arada-Hocate, Maria Nickle. Poster windows: Tuesday 15:00–17:00 and Thursday 14:00–17:00.
- Dr. Endaya, Hazel Anne. Poster window: Saturday 14:00–17:00.

Names come from the imported rows. A booking button appears only when `AppointmentBookingService` returns a next slot. The slot label and the calendar must match that calculation. On 10 November 2026, an isolated check showed Dr. Arada-Hocate’s next demonstration slot as Thursday, 12 November 2026, 2:00–2:30 PM. On a later demonstration date, use the slot the booking service calculates that day. Do not hard-code that Thursday slot into the script.

These doctors have no ordinary public booking slots. The demonstration must not invent one:

- Dr. Eclipse Jr., Eulalio
- Dr. Enriquez, Tyron Vincent P.
- Dr. Saquitan-Epetia, Angeli
- Dr. Epetia, Mar Christopher

Development doctors, including Test Doctor 1, stay hidden while the data source is imported.

## Booking walkthrough

1. The patient chooses a doctor whose card shows a real next slot.
2. The booking URL keeps that doctor’s `clinic_id` and `doctor_id`.
3. The calendar offers only dates inside the seven-day Asia/Manila window.
4. The patient selects an open 30-minute demonstration slot and reviews the doctor, clinic, date, and time.
5. Confirming creates exactly one appointment. Status is pending.
6. The same appointment appears in the patient’s history.

## Staff verification

1. The staff user assigned to Pending hospital mapping logs in and sees that appointment.
2. That user can open it with the existing staff permissions. Do not add a new permission for the demonstration.
3. The staff user assigned to another department does not see it and cannot open it.

## If the demonstration fails

- Guidance spinner or a processing message: the isolated process is still using a blocked key, or the mock was not installed. Reload the mocked path. Do not switch the process to the live key during the defense unless a live call was approved in advance.
- The card names a room, a telephone number, or “Pending hospital mapping” as the destination: stop. That text is not part of the visitor navigation. Reload the isolated imported database and use the listed-service card.
- A safety card shows a booking button: stop. Safety and emergency responses must not open the booking calendar.
- No booking button: the doctor has no calculated slot. Choose a doctor who has one, or confirm provisional local booking is enabled only on the isolated testing process. Do not type a time that is not on the calendar.
- Wrong doctor: stop. The URL must still contain the selected doctor and clinic IDs.
- Duplicate appointment: do not submit again. Check the patient history for the single pending row.
- Staff sees nothing: confirm that staff user is assigned to Pending hospital mapping, not to an empty department and not to a different department.
- Disclaimer returns: the session was cleared. Acknowledge it again.
- To start over, delete the disposable database and repeat the import. Do not delete or reset the live hospital database.

## Known limitations

- Provisional 30-minute slots are for local demonstration. They are not hospital-verified appointment lengths, and the imported hours are pending hospital verification.
- Clinic names copied from specialization labels are temporary. They are not official room assignments.
- Prompt-evaluation and emergency navigation use staff-assistance wording because the imported catalog has no Hospital Verified emergency or urgent-assessment destination. Naming a particular clinic for those concerns needs a reviewed hospital policy first.
- Clinical knowledge is unreviewed and stays off.
- A generic model safety response is still handled differently for a recognized pain concern than for other wording. That difference is awaiting a clinical policy decision.
- One synthetic Front Desk schema call was accepted by the configured model. Further live calls need separate approval. That acceptance was not a clinical judgment.
- Passing the automated suite is not clinical validation.
