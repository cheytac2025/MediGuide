<?php

namespace App\Models;

use App\Enums\ClinicStatus;
use App\Enums\HospitalDataSource;
use App\Models\Concerns\HasHospitalDataSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $department_id
 * @property string $name
 * @property string|null $description
 * @property ClinicStatus $status
 * @property HospitalDataSource $data_source
 * @property Carbon|null $verified_at
 * @property int|null $verified_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $verifiedBy
 * @property-read Department $department
 * @property-read Collection<int, Doctor> $doctors
 */
#[Fillable(['department_id', 'name', 'description', 'status', 'data_source'])]
class Clinic extends Model
{
    use HasHospitalDataSource;

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<Doctor, $this>
     */
    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClinicStatus::class,
            'data_source' => HospitalDataSource::class,
            'verified_at' => 'datetime',
        ];
    }
}
