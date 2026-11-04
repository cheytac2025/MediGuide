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

];
