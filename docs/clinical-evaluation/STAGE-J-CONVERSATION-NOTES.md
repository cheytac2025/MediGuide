# Stage J — Conversational intelligence notes

**Date:** 4 November 2026  
**Status:** Software change only. Clinical review is **not** complete. Reviewer assessments in Documents A–E were not rewritten or filled in.

Stage J improved MediGuide’s original Claude-powered Front Desk conversation memory and follow-up selection. It did **not** copy Ada Health or Infermedica source code, prompts, or clinical engines. Publicly described interaction ideas (one focused question, adaptive follow-up, present / absent / unknown) were adapted for hospital navigation only.

MediGuide is still not a diagnostic tool and not a clinically validated triage system.

## What changed in software behavior

- Session-only structured facts (duration, location, onset, and related optional fields) are rebuilt from visitor turns on each request.
- Follow-up fallbacks are concern-specific instead of a single duration-first script for every complaint.
- Short answers bind to the latest question about the opening concern.
- Headache “likod/back” answers are treated as the head, not a new back-pain complaint.
- Filipino unknown/decline replies use Filipino uncertain wording.
- Combined duration+onset or duration+injury questions are replaced with one focused question.

Laravel emergency and prompt-evaluation overlays were not weakened. Doctor names still come from the hospital catalog. Suggested Doctors UI was not changed.

## Evaluation scenarios to repeat

Repeat these synthetic scenarios from the Stage I packet if hospital review of the live Front Desk is run again. Do not copy Stage H notes as new live results.

| Packet ID | Why repeat |
| --- | --- |
| CV-01 / H4 | Headache follow-up wording and one-question style |
| CV-04 | Abdominal location follow-up |
| CV-05 / CS-07 / H3 | Right-lower abdominal prompt evaluation (safety must still suppress booking) |
| CV-07 / H5 | Cough follow-up (now cough-specific duration wording) |
| CV-11 | Joint follow-up should not combine duration with injury |
| CV-12 | Back pain must not become a headache script |
| CV-13–CV-15 | Skin, ear, and eye follow-ups |
| CV-17 / CV-18 | Pregnancy and pediatric wording |
| CV-21 | Multiple symptoms |
| CV-22 / H7 | English detailed cough still grounds doctors in the catalog |
| CV-23 | Taglish |
| CV-24 | Informal spelling |
| CV-25 / H8 / CS-15 | Unknown answers; language of the uncertain message |
| CS-01 / CS-03 / H1 / H2 | Emergency wording still must not be delayed for a questionnaire |
| CS-14 | Intermittent “bigla-bigla” |

Leave reviewer ratings blank until qualified hospital personnel complete them.

## What was not claimed

- Similarity to Ada Health or Infermedica does not establish clinical validity.
- Automated tests do not establish clinical correctness.
- Structured facts are routing memory, not a patient chart.
