<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Record integrity key
    |--------------------------------------------------------------------------
    |
    | HMAC-SHA-256 key for patient and appointment seals. This is not APP_KEY
    | and must not be derived from it. Generate 32 random bytes:
    |
    |   php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
    |
    | Store the value in the environment and in an offline secret store that
    | is kept apart from database backups. Losing it makes every seal fail
    | verification. The application will not print this value.
    |
    | Rotation: put the current version and key into
    | MEDIGUIDE_INTEGRITY_PREVIOUS_KEYS as "version:base64:...", increase
    | MEDIGUIDE_INTEGRITY_KEY_VERSION, and set a new MEDIGUIDE_INTEGRITY_KEY.
    | Existing seals keep verifying with the previous key. New writes use the
    | current version. Then run mediguide:integrity-rotate. It is a dry run
    | unless --execute is passed. It re-signs only seals that still verify.
    | Unsigned rows are not enrolled. Invalid rows are never re-signed.
    | Remove a previous key only after those records have been re-signed.
    |
    | Direct SQL is outside the application transaction. It is detected, not
    | prevented. Eloquent bulk updates of patients and appointments are rejected.
    |
    | Deleting a seal does not return a protected row to the legacy unsigned
    | state. integrity_subjects keeps that fact and the last trusted slot.
    | mediguide:integrity-recover can replace a missing seal only. It is a dry
    | run unless --execute is passed, and it never repairs a bad HMAC.
    |
    */

    'key' => env('MEDIGUIDE_INTEGRITY_KEY'),

    'key_version' => (int) env('MEDIGUIDE_INTEGRITY_KEY_VERSION', 1),

    'previous_keys' => env('MEDIGUIDE_INTEGRITY_PREVIOUS_KEYS', ''),

    /*
    |--------------------------------------------------------------------------
    | Strict verification
    |--------------------------------------------------------------------------
    |
    | Leave this false until every current patient and appointment has a
    | matching seal. When true, unsigned rows are refused for protected
    | writes and are hidden like an invalid seal.
    |
    */

    'strict' => (bool) env('MEDIGUIDE_INTEGRITY_STRICT', false),

    /*
    |--------------------------------------------------------------------------
    | Payload version
    |--------------------------------------------------------------------------
    |
    | Bumped only when the canonical field list changes. Seals record the
    | version they were computed with.
    |
    */

    'version' => 1,

];
