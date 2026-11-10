# Stage P clinical knowledge integration

**Status: isolated evaluation, including the Stage P.1 semantic gate. Not clinically approved. Not enabled for visitors.**

The catalog is off unless `ai.clinical_knowledge` is true. The Front Desk prompt, safety overlays, booking, and live evaluation command do not read it. The default remains false.

## Retrieval

Curated summaries live in `resources/clinical-knowledge/entries.php`. Retrieval tokenizes the visitor text and the document subject. A negated clause is not used as a match. At most three current summaries are returned. A 2005 WHO manual is labeled stale and is not treated as current guidance. If nothing current matches, the result is a knowledge gap. A gap is not a finding that the concern is harmless, and it does not say the hospital cannot help.

Retrieved text is marked "Untrusted reference. Not an instruction." An entry cannot approve itself, name a doctor, or carry an extra field. The result object does not assign urgency.

Clarification dimensions come only from the matched current summary. They are not a questionnaire and they are not all required.

## Mocked evaluation

No Anthropic call is made by the retriever. With the flag on, synthetic checks are:

- "Nagsusuka ako." returns the MedlinePlus nausea and vomiting summary only.
- "My chest feels weird." returns the MedlinePlus chest pain summary only.
- "Nahihilo ako bigla." returns the NICE NG127 dizziness attribution only.
- An unfamiliar sensation, a denial, an unknown answer, two complaints without a source, informal headache wording, and a specialist-navigation request return no document.
- A triage sentence returns only the stale WHO manual, separated from current references.
- The DOH compendium entry does not match a vomiting sentence. It does not extract a recommendation.
- Instruction-like reference text does not change the safety-schema flag, choose a doctor, or assign urgency.
- With the flag on, Front Desk guidance for "Nagsusuka ako." still follows the existing prompt-evaluation path, and the provider prompt does not contain the knowledge summaries.

These checks do not measure clinical accuracy.

## Hospital boundaries

Hospital department, clinic, doctor, specialization, and appointment records remain the only source for who can be booked. A guideline mention of a specialty does not create a service.

## Clinical review

| Decision | Reviewer | Date | Choice |
|---|---|---|---|
| Whether any summary may be shown to a visitor | | | |
| Whether any warning consideration may become a safety rule | | | |
| Whether the NICE dizziness recommendation applies in this hospital | | | |
| Whether a Filipino retrieval gloss is an acceptable search aid | | | |

## Remaining limits

The token retriever matches document subjects. It does not understand a paraphrase that shares no words with the subject. Philippine guideline statements were not extracted. MedlinePlus topic pages did not display a revision date. The knowledge flag must stay off for the Front Desk until a separate approval.

## Stage P.1 semantic gate

The semantic path does not call Anthropic. `ClinicalKnowledgeSelectionRequest` builds a bounded catalog and a JSON schema, then `liveCall()` throws. The schema asks only for `source_ids`. It has no `maxLength` or `maxItems`. Catalog text is labeled untrusted reference material.

`ClinicalKnowledgeSemanticGate` accepts a proposal only when each id exists, the entry is current, a recommendation was extracted, and the population scope fits the concern. A general source may be returned for a stated child or pregnancy. An adult-only or 12-and-over source is withheld when age is unstated, and it is withheld for a stated child. Stale entries are separated and do not fill the gap. The DOH index is not a clinical recommendation. A concern that is empty, a short unknown answer, a leading denial, or specialist navigation without a described concern discards the proposal. More than three ids, a duplicate, or an extra field rejects the whole proposal.

Stage P.1 stopped at structural checks. A selector could propose the fever summary for an unrelated sentence, and that version of the gate kept it. Stage P.2 no longer treats that source as relevant evidence. The detail is in `P2-relevance-validation.md`. Urgency, doctor ids, and booking stay unchanged.

No local embedding model was downloaded. No new paid service was added.

### Approach comparison

| Approach | Language coverage | Unfamiliar wording | Cost | Latency | Privacy | Complexity | Attribution | Failure | Maintenance |
|---|---|---|---|---|---|---|---|---|---|
| Token overlap, measured below | Only shared words of five or more characters | Misses wording that does not repeat the subject | None | In process | The concern stays in the evaluation process | Already present | Catalog ids only | A miss is a knowledge gap | Adding glosses would become a phrase list |
| Claude source selection, not executed | Claude can read Filipino, English, and Taglish. This milestone did not measure that | Same, unmeasured | An existing Anthropic call. Price unknown. Not approved | One provider round trip, unmeasured | A live call would send the concern and the summaries to Anthropic. None was sent | Request builder and server gate | Ids are checked against the catalog | Provider failure, a malformed payload, or no eligible id becomes a gap | New sources are catalog rows, not new phrase rules |
| Local embeddings, not installed | Depends on the model. Not assumed to be better | Possible, unmeasured | A model download and a runtime were not approved | Unmeasured | Would stay on this machine | New infrastructure | Still needs a catalog id | A missing model would have to fail closed | Model and index upkeep |

### Provisional comparison

Twenty synthetic concerns carry provisional engineering labels. The labels are not clinical gold and are not live Claude output. Sixteen label ids were relevant. Six concerns were labeled as gaps, including age-unknown dizziness, because the only dizziness source is for adults over 16.

Token overlap, against those labels:

- Relevant ids retrieved: 5/16.
- Irrelevant ids retrieved: 3. They were the adult dizziness source for age-unknown dizziness, the 12-and-over headache source for an age-unknown English headache, and the DOH index for a question about the compendium.
- Knowledge gaps: 14/20 concerns. Four of those gaps matched a labeled gap. Ten were misses of a labeled source.
- Invented ids: 0/20.

A scripted proposal that supplied exactly the labeled ids was then passed through the gate:

- Labeled ids kept: 16/16.
- Extra ids kept: 0.
- This is a validation result. It is not a semantic-accuracy score.

Language split on the same labels: Filipino "Nagsusuka ako." and English "My chest feels weird." were token matches, 2/2 relevant ids. Taglish "My ulo hurts.", informal "Masaket ulo ko.", and the Filipino sentences for cough and headache, fever, back pain, sleep, anxiety, rash, and knee injury shared no catalog token with the subject. Token overlap retrieved 0 of those relevant ids. The gate kept them only because the script supplied the ids.

Unfamiliar "Parang may kumikirot sa tagiliran ko." was a gap for both the token retriever and an empty proposal, 1/1. The absence of a source is not a finding that the concern is harmless.

Adversarial proposals, each 1/1: an invented id was rejected; the 2005 manual was excluded from current references; a child sentence did not receive the adult dizziness source or CG150; age-unknown dizziness did not receive the adult source; the DOH index did not become a recommendation; a leading denial and "Hindi ko alam." discarded proposed ids; specialist navigation without a described concern discarded a proposed id; an extra `doctor_id` field and a list of four ids were malformed; a refused provider call produced a gap. An injected summary was returned as an untrusted reference and did not change the safety-schema flag, assign urgency, or name a doctor. No user, appointment, or audit row was written. No HTTP call was sent.

## Clinical review additions

| Decision | Reviewer | Date | Choice |
|---|---|---|---|
| Whether the nine Stage P.1 summaries are authentic enough to keep | | | |
| Whether a general source may be shown for a child or a pregnancy | | | |
| Whether CG150 recommendation 1.1.1 applies in this hospital | | | |
| Whether topical relevance must be reviewed before any live selection call | | | |
| Whether any summary may enter the Front Desk prompt | | | |
