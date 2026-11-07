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
    | administrator can still update or delete rows. Verification detects a
    | changed or broken chain when the rows that remain no longer match.
    |
    | MEDIGUIDE_AUDIT_CHECKPOINT_PATH is intentionally empty until an
    | operator chooses an absolute file outside the web root. An empty path
    | leaves checkpoint protection inactive: mediguide:audit-verify checks
    | the database chain only and says that protection is inactive. Tail
    | deletion of a still-valid chain is not detected in that mode.
    |
    | A non-empty path that is relative, contains "..", or sits inside this
    | project or its parent directory is misconfigured. It is not treated
    | as inactive. Verification exits nonzero and does not print the path.
    |
    | When the path is accepted, verification reads the latest checkpoint
    | record only. A missing, unreadable, empty, malformed, incomplete, or
    | invalid latest record fails verification. An invalid signature or an
    | unknown key version also fails. A corrupt latest line is not replaced
    | by an older line. Verification does not create, replace, repair, or
    | re-sign the file.
    |
    | The first file is created only by an explicit
    | `php artisan mediguide:audit-checkpoint --execute` after the path is
    | configured. Dry-run writes nothing. A missing file can fail
    | verification and still be created by that command.
    |
    | A checkpoint records sequence N. The head may be newer than N. The head
    | may not be older than N. When N is greater than zero, the verified
    | event at N must carry the checkpoint tag. When N is zero, the tag must
    | be the genesis tag for the checkpoint's key version. Verification
    | reports N, the current head, and how many later events the checkpoint
    | does not cover.
    |
    | Deletion confined to events after the checkpoint may remain undetected
    | when the remaining chain is still valid and its head is still N. Store
    | a newer checkpoint to cover events appended after N. A sequence of zero
    | anchors the genesis tag only.
    |
    | On MySQL and MariaDB, mediguide:audit-verify must start its own
    | transaction. If a transaction is already open, verification fails
    | before it reads the checkpoint or requests an isolation level. It does
    | not commit or roll back the caller's transaction. The command reports
    | that failure and exits nonzero.
    |
    | The checkpoint file is read before that transaction opens. Verification
    | then reads the ordered audit_events rows in one SELECT and the single
    | audit_chain_state row in one SELECT, on that same connection. Neither
    | query is chunked, uses a cursor, or takes a row lock. InnoDB at
    | REPEATABLE READ keeps those two reads on one snapshot. The request
    | applies to verification's transaction only and does not change the
    | server default. A checkpoint appended after the file is read can belong
    | to a newer commit. It is not compared with the earlier snapshot, so it
    | cannot by itself look like a truncated chain.
    |
    | SQLite tests keep the test runner's transaction. That behavior does not
    | establish MySQL or MariaDB concurrency coverage, and the tests do not
    | open two database connections. While MEDIGUIDE_AUDIT_CHECKPOINT_PATH is
    | empty, protected checkpoint storage and off-machine retention are
    | inactive.
    |
    */

    'key' => env('MEDIGUIDE_AUDIT_KEY'),

    'key_version' => (int) env('MEDIGUIDE_AUDIT_KEY_VERSION', 1),

    'previous_keys' => env('MEDIGUIDE_AUDIT_PREVIOUS_KEYS', ''),

    'version' => 1,

    'checkpoint_path' => env('MEDIGUIDE_AUDIT_CHECKPOINT_PATH', ''),

];
