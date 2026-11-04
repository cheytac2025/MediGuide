<?php

namespace App\Models\Concerns;

use App\Enums\HospitalDataSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasHospitalDataSource
{
    /**
     * @param  Builder<static>  $query
     */
    public function scopeForAiGrounding(Builder $query): void
    {
        $query->whereIn(
            $query->qualifyColumn('data_source'),
            HospitalDataSource::current()->allowedValues(),
        );
    }

    public function isEligibleForAiGrounding(): bool
    {
        $source = $this->data_source instanceof HospitalDataSource
            ? $this->data_source
            : HospitalDataSource::Development;

        return HospitalDataSource::current()->allows($source);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
