<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit trail key
    |--------------------------------------------------------------------------
    |
    | HMAC-SHA-256 key for the append-only audit chain. This is not APP_KEY
    | and it is not MEDIGUIDE_INTEGRITY_KEY. Generate 32 random bytes:
    |
    |   php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
    |
    | Keep a copy outside this computer before relying on the chain. The
    | application does not print the key. Losing it makes existing events
    | fail verification. New events use MEDIGUIDE_AUDIT_KEY_VERSION.
    |
    | A future rotation copies the current key into MEDIGUIDE_AUDIT_PREVIOUS_KEYS
    | as "version:base64:..." and increases the version. This milestone does
    | not rotate the key. Do not drop a previous key while events still use it.
    |
    | MySQL cannot make a table immutable. Application code only appends, and
    | each event carries an HMAC linked to the previous event. A database
    | administrator can still update or delete rows. Verification detects
    | those changes when the rows remain. Deleting the newest events, or the
    | whole chain, is detected only when an independently stored checkpoint
    | of the chain head still exists. Leave MEDIGUIDE_AUDIT_CHECKPOINT_PATH
    | empty until that file is outside the web root and copies are kept
    | off this computer. The checkpoint command is a dry run until then.
    |
    */

    'key' => env('MEDIGUIDE_AUDIT_KEY'),

    'key_version' => (int) env('MEDIGUIDE_AUDIT_KEY_VERSION', 1),

    'previous_keys' => env('MEDIGUIDE_AUDIT_PREVIOUS_KEYS', ''),

    'version' => 1,

    'checkpoint_path' => env('MEDIGUIDE_AUDIT_CHECKPOINT_PATH', ''),

];
