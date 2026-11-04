<?php

namespace App\Models;

use App\Enums\DepartmentStatus;
use App\Enums\HospitalDataSource;
use App\Models\Concerns\HasHospitalDataSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property DepartmentStatus $status
 * @property HospitalDataSource $data_source
 * @property Carbon|null $verified_at
 * @property int|null $verified_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $verifiedBy
 * @property-read Collection<int, Clinic> $clinics
 * @property-read Collection<int, HospitalStaff> $hospitalStaff
 */
#[Fillable(['name', 'description', 'status', 'data_source'])]
class Department extends Model
{
    use HasHospitalDataSource;

    /**
     * @return HasMany<Clinic, $this>
     */
    public function clinics(): HasMany
    {
        return $this->hasMany(Clinic::class);
    }

    /**
     * @return BelongsToMany<HospitalStaff, $this>
     */
    public function hospitalStaff(): BelongsToMany
    {
        return $this->belongsToMany(HospitalStaff::class, 'department_hospital_staff')
            ->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DepartmentStatus::class,
            'data_source' => HospitalDataSource::class,
            'verified_at' => 'datetime',
        ];
    }
}
