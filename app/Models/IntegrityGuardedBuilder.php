<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Blocks bulk writes that would change a protected row without its seal.
 *
 * Model::save() is allowed through. Direct SQL remains detectable, not preventable.
 */
class IntegrityGuardedBuilder extends Builder
{
    public bool $allowModelWrite = false;

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values)
    {
        if (! $this->allowModelWrite) {
            throw new RuntimeException('Protected patient and appointment rows must be saved as models so the integrity seal commits with the change.');
        }

        return parent::update($values);
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     */
    public function insert(array $values)
    {
        throw new RuntimeException('Protected patient and appointment rows must be saved as models so the integrity seal commits with the change.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new RuntimeException('Protected patient and appointment rows must be saved as models so the integrity seal commits with the change.');
    }
}
