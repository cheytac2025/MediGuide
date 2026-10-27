<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Rejects query-builder writes against audit history.
 *
 * DB::table() can still change rows. Verification detects that. A database
 * administrator is outside this restriction.
 *
 * @template TModel of AuditEvent
 *
 * @extends Builder<TModel>
 */
class AuditEventBuilder extends Builder
{
    public function update(array $values)
    {
        throw new RuntimeException('Audit events are append-only and cannot be updated.');
    }

    public function delete()
    {
        throw new RuntimeException('Audit events are append-only and cannot be deleted.');
    }

    public function insert(array $values)
    {
        throw new RuntimeException('Audit events must be written by the audit trail service.');
    }

    public function insertOrIgnore(array $values)
    {
        throw new RuntimeException('Audit events must be written by the audit trail service.');
    }

    public function insertGetId(array $values, $sequence = null)
    {
        throw new RuntimeException('Audit events must be written by the audit trail service.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new RuntimeException('Audit events are append-only and cannot be upserted.');
    }
}
