<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Read model for an audit event. Rows are inserted only by AuditTrailService.
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function save(array $options = [])
    {
        throw new RuntimeException('Audit events are append-only and cannot be saved through the model.');
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new RuntimeException('Audit events are append-only and cannot be updated.');
    }

    public function delete()
    {
        throw new RuntimeException('Audit events are append-only and cannot be deleted.');
    }

    /**
     * @param  Builder  $query
     */
    public function newEloquentBuilder($query): AuditEventBuilder
    {
        return new AuditEventBuilder($query);
    }
}
