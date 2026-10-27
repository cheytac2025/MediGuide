<?php

namespace App\Models\Concerns;

use App\Models\IntegrityGuardedBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Events\NullDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Opens the integrity transaction before the protected row is written.
 *
 * The seal is written by the observer before this transaction commits.
 * A nested call uses a savepoint, so an outer booking transaction still
 * rolls both the row and the seal back together.
 */
trait WritesRecordIntegrity
{
    private bool $integrityWrite = false;

    public function save(array $options = [])
    {
        $dispatcher = static::getEventDispatcher();

        if ($dispatcher instanceof NullDispatcher) {
            throw new \RuntimeException('Integrity-protected records cannot be saved without model events.');
        }

        if ($this->integrityWrite) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options) {
            $this->integrityWrite = true;

            try {
                return parent::save($options);
            } finally {
                $this->integrityWrite = false;
            }
        });
    }

    public function newEloquentBuilder($query): IntegrityGuardedBuilder
    {
        return new IntegrityGuardedBuilder($query);
    }

    protected function performUpdate(Builder $query)
    {
        if ($query instanceof IntegrityGuardedBuilder) {
            $query->allowModelWrite = true;
        }

        return parent::performUpdate($query);
    }
}
