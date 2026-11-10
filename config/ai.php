<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prompt-evaluation overlay
    |--------------------------------------------------------------------------
    |
    | Conservative Laravel safeguards for concerning presentations that may
    | warrant prompt in-person evaluation even when an emergency cannot be
    | established from the conversation.
    |
    | This is not a triage protocol, diagnosis, or clinical scoring tool.
    | Qualified hospital personnel must review it before real-world use.
    |
    | enabled  — limited, well-supported navigation safeguards (default on).
    | extended — broader candidate patterns that stay off until clinicians
    |            approve them. Do not enable in production without review.
    |
    */

    'prompt_evaluation' => [
        'enabled' => (bool) env('AI_PROMPT_EVALUATION_ENABLED', true),
        'extended' => (bool) env('AI_PROMPT_EVALUATION_EXTENDED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety evidence schema experiment
    |--------------------------------------------------------------------------
    |
    | When false, Claude's structured safety response stays type and message.
    | Turning this on asks the provider for evidence fields and can change
    | model behavior. Leave it off until that schema change is approved.
    | Evidence validation still runs in shadow mode on any fields that are
    | already present. It does not change the visitor-facing response.
    |
    */

    'safety_evidence_schema' => filter_var(env('AI_SAFETY_EVIDENCE_SCHEMA', false), FILTER_VALIDATE_BOOL),

];
