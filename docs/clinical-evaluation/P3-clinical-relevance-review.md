# Stage P.3 clinical relevance review

**MediGuide AI Virtual Front Desk**

**Hospital context:** Queen Mary Help of Christians Hospital, Inc., Cardona, Rizal

**Status:** Awaiting clinical review. No reviewer has completed this package. MediGuide is not approved for diagnosis, triage, or unsupervised patient use.

This guide explains how a qualified reviewer assesses whether a curated source fits a synthetic concern. It does not change the patient-facing AI Front Desk. It does not add a safety rule. It does not authorize a live model call.

## What you are reviewing

The software holds 14 unreviewed source summaries. Thirty-three synthetic scenarios propose some of those sources. Your task is to judge the relationship between the concern and the proposed source, and, separately, whether using that source in a hospital navigation assistant would be safe.

A source can fit the topic and still be unsafe to show. A source can be safe to withhold. Relevance is not proof that the concern is medically harmless, and a missing source is not proof that the hospital cannot help.

Engineering labels in the worksheet were written by software evaluation. They are not clinical ratings. Do not copy them into the clinical cells unless you independently agree, and if you agree, write your own choice.

## Documents in this package

| Document | Use |
| --- | --- |
| This guide | How to rate, what the software does not do, and how disagreement is handled |
| `P3-relevance-worksheet.md` | One block for each existing scenario-source pair. Clinical cells are blank |
| `P3-review-decision-form.md` | One form per reviewer for identity, overall comments, and a recommendation |

Print the HTML copies on A4. Word copies are produced only when the existing export tool has its optional library. No new document tool was installed.

## How to complete the worksheet

Use only the synthetic sentences printed on the worksheet. Do not add real patient information.

For each block, choose one relevance value and one safety value. Leave a block as **Not Evaluated** if you did not assess it. A blank cell is not an approval and is not a rating.

Relevance:

- Relevant
- Possibly Relevant
- Irrelevant
- Insufficient Information
- Population Inapplicable
- Stale or Unsupported
- Not Evaluated

Safety, kept separate from relevance:

- Appropriate
- Needs Revision
- Unsafe
- Not Applicable
- Not Evaluated

Applicability:

- Applicable
- Limited
- Not Applicable
- Not Evaluated

Applicability asks whether the source's stated population and scope can be used for this synthetic concern in this hospital. It does not select a doctor, a clinic, or a department. Hospital records remain the only source for who can be booked.

## What the software already does

The public Front Desk does not read this catalog. The knowledge flag stays off unless `AI_CLINICAL_KNOWLEDGE` is set, and it is not set for visitors.

Before any relevance label, the software rejects an unknown source ID, a duplicate, more than three IDs, a stale entry as current guidance, an index that is not a recommendation, a population mismatch, a leading denial, a short unknown answer, and specialist navigation that does not describe a concern. Those checks do not establish that a remaining source fits the concern.

The default relevance assessor abstains. A shared subject word can mark a source "possibly relevant" in tests. It cannot mark a source relevant. No live Claude selection has been run for this package. Token-overlap counts and scripted proposals from earlier stages are simulated or software-tested results. They are not observed clinical accuracy.

The Department of Health compendium is in the catalog and is not paired with a scenario, because no individual guideline statement was extracted from it. The 2005 WHO manual is paired so a reviewer can see a stale source. MedlinePlus topic pages did not display a revision date. NICE dates are the dates printed on the cited pages. Summaries are short attributions, not copies of the guidelines.

## Benchmark

Completed worksheets can later be loaded as structured rows. The loader keeps reviewer rows separate from engineering labels and from any future model proposal.

It rejects an unknown scenario ID, an unknown source ID, a source that was not proposed for that scenario, a duplicate reviewer-and-pair row, an invalid rating, and a row with a blank reviewer name, designation, credentials, institution, date, or signature. **Not Evaluated** is a missing assessment. It is not a score. An empty file, or a file whose ratings are all Not Evaluated, has status `awaiting_clinical_review`.

The loader does not compute accuracy. It does not treat engineering labels as the correct answer. It does not promote a knowledge entry to approved. It does not change retrieval, urgency, or booking.

The current assessment file is empty. The benchmark status is `awaiting_clinical_review`.

## Disagreement

When two reviewers give different relevance ratings, or different safety ratings, for the same scenario and source, the result records both ratings and both comments. It does not average them. Safety disagreement is not resolved by taking the milder rating.

A single reviewer's judgment is not hospital approval. Agreement between reviewers is not, by itself, approval of the catalog or of patient-facing use.

A final decision is recorded only when an adjudicator submits an explicit adjudication record with a name, designation, credentials, institution, date, and signature. That record does not approve the knowledge base and does not create a new urgency rule.

## Approval boundary

Queen Mary Help of Christians Hospital, Inc. has not approved this knowledge base, these engineering labels, or the AI Front Desk through this package. Identity, signature, and decision fields on the decision form stay blank until a qualified reviewer completes them.

No entry may be marked clinician-approved from this review until a separate, explicit decision says so. Warning text in a source is not an active MediGuide safety rule.

## Remaining decisions

| Decision | Reviewer | Date | Choice |
| --- | --- | --- | --- |
| Whether any summary may be shown to a visitor | | | |
| Whether any warning consideration may become a safety rule | | | |
| Whether a possibly relevant source may be shown to staff during evaluation | | | |
| Whether the engineering labels may be used only as a worksheet aid | | | |
| Whether one isolated model selection call may be compared with completed reviews | | | |
