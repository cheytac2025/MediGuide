# Stage N safety-evidence policy proposal

**Status: not approved. Not activated. Not a clinical protocol.**

This note records a proposed future policy for qualified hospital review. The software implemented in Stage N only checks evidence in shadow mode. It does not change what a visitor sees, and it does not approve the wording below.

## What is implemented now

Laravel can parse a structured safety object with `basis`, `evidence` quotations, `proposed_level`, and `message`. It checks quotations against server-side visitor statements. The result is an internal status code. That status is not shown to the visitor and does not select emergency guidance, prompt evaluation, clarification, or a doctor recommendation.

The live provider schema still asks Claude only for `type` and `message`. A schema that also requests evidence fields exists behind `ai.safety_evidence_schema`, which defaults to off.

## Proposed unresolved hold

Not activated.

When a model safety response has no quotation that matches an already reviewed overlay, a future policy could stop routine booking without assigning emergency or prompt-evaluation status. That hold is not in the visitor interface.

Draft wording, not approved:

- English: "I can't confirm a warning sign from the words you sent, and I should not continue routine booking while that is unresolved. Please ask hospital staff for assistance. This is not a diagnosis."
- Filipino: "Hindi ko makumpirma mula sa mga salitang ipinadala ninyo na may warning sign, at hindi dapat ituloy ang karaniwang online booking habang hindi ito malinaw. Mangyaring humingi ng tulong sa staff ng ospital. Hindi ito diagnosis."

## Evidence and model limits

A quotation proves only that the visitor wrote those words. It does not prove urgency. The model's `proposed_level` is not authoritative. Negated words, "Hindi ko alam," and an unanswered assistant question are not positive reports. "Oo" does not copy the assistant's question into the visitor's report. Words the visitor did not write are unsupported, including a partial word inside a longer word.

The checker does not understand every clinical implication of a sentence. It does not add warning signs such as vomiting blood or inability to keep fluids down. Those would be separate overlay changes and are not proposed for activation here.

## Cases for later review

| Situation | Proposed future handling | Active now |
|---|---|---|
| Visitor reports an existing emergency phrase | Immediate emergency guidance | Yes, current overlay |
| Visitor reports an existing prompt-evaluation pattern | Prompt evaluation and no routine booking | Yes, current overlay |
| Vague complaint and generic model safety, such as isolated vomiting or an undescribed sensation | Unresolved hold, after wording approval | No. Current software still uses the pain-severity exception or prompt evaluation |
| Recognized pain complaint with severity missing and generic model safety | Same hold, if the pain-specific exception is retired | No. The current exception still asks a follow-up |
| Model invents a warning sign | Do not treat the invented sign as reported | Shadow check only. Visitor response unchanged |
| Valid quotation with no reviewed rule | Hold, not a new urgency rule | Shadow check only |
| Enough routing detail and a real service id | Hospital-grounded recommendation | Yes, unchanged |
| Visitor asks for staff, or the catalog cannot support a route | Staff assistance | Yes, current uncertain path |

## Approval still required

1. Approve or replace the hold wording in English and Filipino.
2. Decide whether unsupported model safety should use that hold instead of today's split between a pain follow-up and prompt evaluation.
3. Confirm that a quotation creates urgency only when it matches an overlay the hospital has already reviewed.
4. Decide separately whether any new warning-sign phrases should be added.
5. Choose a visitor-facing title that is not "Prompt medical evaluation" if the hold is approved.

Until those decisions are recorded as approved, the current visitor-facing safety behavior remains in force.
