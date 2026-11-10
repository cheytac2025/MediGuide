# Stage P.2 relevance validation

**Status: isolated evaluation. Not clinically approved. Not enabled for visitors. No live Claude call.**

## Root cause

`ClinicalKnowledgeSemanticGate` used to append a catalog entry to the accepted list after the ID, schema, count, population, stale, and index checks. Those checks do not compare the concern with the source scope. A valid fever ID could be returned for an unrelated knee or triage sentence. The Stage P.1 result of 16/16 was a scripted proposal passing that structural gate. It was not semantic retrieval accuracy.

## Classification

The categories are retrieval labels, not urgency and not a diagnosis:

| Level | Meaning |
|---|---|
| relevant | A separate judgment says the source scope fits this concern |
| possibly_relevant | The relationship is uncertain, or only the existing subject-token overlap supports it |
| irrelevant | A separate judgment says the source does not fit |
| insufficient_information | The concern does not carry enough information to use the source |
| population_inapplicable | The source population does not fit the stated age or pregnancy context |
| stale_or_unsupported | The source is stale, or it is an index and not a recommendation |
| unassessed | No independent judgment was available, and subject tokens did not overlap |

A relevant label does not mean the concern is medically safe. An empty or unassessed result does not mean the concern is harmless, and it does not mean the hospital cannot help.

## Validation

The selector still returns only source IDs. The schema has no relevance field. An extra relevance field, a duplicate, or more than three IDs rejects the whole proposal.

After the structural checks, a relevance assessor runs. The default assessor abstains (`unassessed`). Subject-token overlap can raise that result to `possibly_relevant`. It cannot raise a source to `relevant`, and lack of overlap cannot force `irrelevant`. Confirmed relevant sources are the only ones that close the knowledge gap and the only ones that supply clarification dimensions.

Population, stale status, leading denial, a short unknown answer, and specialist navigation without a described concern are decided before the assessor. A judgment of `relevant` cannot reopen them.

## Independent evaluation

A second model call was not added. It would be another Anthropic request, the price is unknown, the concern and the summaries would leave the process, and a model grading its own IDs would not be an independent judge. It would also not be clinical validation.

The reproducible labels live in `tests/Evaluation/ClinicalKnowledge/EngineeringRelevanceLabels.php`. The proposal list and the judgment are separate fields. The test judge reads only the judgment for that concern and source. These labels are engineering labels. No clinician rating was created.

## Synthetic results

Thirty-three scenarios. Seventeen source IDs were labeled relevant and were structurally allowed. Eight were labeled irrelevant.

With the independent engineering judge:

- Relevant IDs confirmed: 17/17.
- Those IDs rejected as irrelevant: 0/17.
- Irrelevant IDs placed in the relevant list: 0/8.
- An ambiguous anxiety proposal for a vague sensation stayed possibly relevant, not relevant.
- "Masakit." with a headache proposal stayed insufficient.
- A child dizziness proposal and an age-unknown CG150 proposal stayed population-inapplicable even though the engineering label said relevant.
- The 2005 WHO manual stayed stale.
- An invented ID, a leading denial, "Hindi ko alam.", and a specialist-navigation request did not become relevant.

With the default abstaining judge, on the same 17 labeled-relevant IDs:

- Confirmed relevant: 0/17.
- Possibly relevant from subject-token overlap: 5/17. Those were Filipino vomiting, English chest pain, English "headache", and the two adult headache sources.
- Unassessed: 12/17. This includes Taglish "My ulo hurts.", informal "Masaket ulo ko.", and the Filipino sentences for cough, headache, fever, back pain, sleep, anxiety, rash, and knee injury.
- Called irrelevant despite a relevant label: 0/17.

The eight irrelevant proposals were also absent from the relevant list under the default judge. Abstention, not a keyword rejection, is what kept them out. That is why Filipino paraphrases are not falsely marked irrelevant, and also why they are not confirmed.

## Clinical limits

No entry was marked approved. Warning considerations were not turned into safety rules. No doctor or specialization was inferred. The Front Desk prompt was not changed. The knowledge flag remains off unless `AI_CLINICAL_KNOWLEDGE` is set.

## Clinical review

| Decision | Reviewer | Date | Choice |
|---|---|---|---|
| Whether any relevance level may be shown to a visitor | | | |
| Whether `possibly_relevant` is acceptable as a staff-only evaluation label | | | |
| Whether the engineering labels may be used as a review worksheet | | | |
| Whether a separate human or model review should replace abstention | | | |
