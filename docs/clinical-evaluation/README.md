# MediGuide Clinical Evaluation Package

**System:** MediGuide AI Virtual Front Desk  
**Hospital context:** Queen Mary Help of Christians Hospital, Inc., Cardona, Rizal  
**Package version:** Stage I — 4 November 2026  
**Clinical approval status:** Not reviewed. All reviewer and approval fields are blank.

This folder contains the hospital reviewer package for MediGuide’s Claude-powered AI Virtual Front Desk. It supports capstone evaluation of conversational hospital navigation. It does **not** authorize MediGuide as a diagnostic or triage system.

## Documents

| ID | Document | File |
| --- | --- | --- |
| A | Clinical Evaluation Guide | `A-clinical-evaluation-guide.md` |
| B | AI Conversation Evaluation Matrix | `B-ai-conversation-evaluation-matrix.md` |
| C | Clinical Safety Review Matrix | `C-clinical-safety-review-matrix.md` |
| D | Doctor Recommendation Evaluation | `D-doctor-recommendation-evaluation.md` |
| E | Reviewer Assessment Form | `E-reviewer-assessment-form.md` |

Printable HTML copies use the same filenames with a `.html` extension and `print.css`. Open the HTML in a browser and use Print (A4) for paper copies. Word (`.docx`) copies are generated when the export tool is available.

Related existing project documentation (not replaced by this package):

- `docs/ai-front-desk-clinical-evaluation-checklist.md` — earlier engineering checklist; still blank pending hospital review
- `docs/hospital-import-preview.md` — imported catalog reference; not a verified operational schedule
- `docs/clinical-evaluation/STAGE-J-CONVERSATION-NOTES.md` — software conversation changes after Stage I; repeat listed scenarios; do not treat as clinical approval
- `docs/clinical-evaluation/STAGE-K-READINESS-NOTES.md` — recommendation-readiness software change after Stage J, including one observed synthetic back-pain transcript; additional questions are not clinical safety approval
- `docs/clinical-evaluation/P3-clinical-relevance-review.md` — Stage P.3 guide for source relevance. The worksheet and decision form are blank. Status: awaiting clinical review

## Scenario counts

| Document | IDs | Count |
| --- | --- | --- |
| B Conversation matrix | CV-01 to CV-26 | 26 |
| C Safety review | CS-01 to CS-15 | 15 |
| D Doctor recommendation | DR-01 to DR-15 | 15 |
| E Reviewer assessment | one form per reviewer | — |

Document B covers the required conversation topics as follows:

| Required topic | Scenario IDs |
| --- | --- |
| Headache | CV-01, CV-02, CV-03 |
| Abdominal pain | CV-04, CV-05 |
| Fever | CV-06, CV-26 |
| Cough | CV-07, CV-22 |
| Chest pain | CV-08, CV-09 |
| Breathing difficulties | CV-10 |
| Joint pain | CV-11 |
| Back pain | CV-12 |
| Skin conditions | CV-13 |
| Ear concerns | CV-14 |
| Eye and vision concerns | CV-15, CV-16 |
| Pregnancy-related concerns | CV-17 |
| Pediatric concerns | CV-18, CV-19 |
| Ambiguous symptoms | CV-20 |
| Multiple symptoms | CV-21 |
| Filipino conversations | CV-01, CV-04, CV-18, CV-26 |
| English conversations | CV-22 |
| Taglish conversations | CV-23 |
| Informal spelling variations | CV-24 (same live wording as CV-01) |
| Unknown or declined answers | CV-25 |

Do not calculate clinical accuracy or approval percentages before reviewers complete the forms.

## How to use

1. Read Document A before running scenarios.
2. Use only synthetic patient statements listed in Documents B, C, and D.
3. Record ratings and comments in the blank fields. Do not pre-fill results.
4. Complete one copy of Document E per reviewer.
5. Leave items as **Not Observed** or **Not Evaluated** when a scenario was not run.

## Evidence classes used in this package

| Label | Meaning |
| --- | --- |
| Expected software behavior | What the current implementation is designed to do |
| Observed (Stage H live) | Recorded during bounded live Claude testing with synthetic concerns |
| Software-tested | Covered by automated tests with mocked Claude responses |
| Not observed | No live Claude run for this wording in Stage H |
| Clinically approved | Must remain blank until qualified hospital personnel complete review |

Automated tests (latest reported baseline: 857 passed, 4,704 assertions) do not establish clinical correctness.

## Privacy

Use synthetic statements only. Do not enter real patient names, contact numbers, birth dates, or medical records into the AI Front Desk or into these forms.
