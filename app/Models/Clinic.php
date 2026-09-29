<?php

namespace App\Models;

use App\Enums\ClinicStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $department_id
 * @property string $name
 * @property string|null $description
 * @property ClinicStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Department $department
 */
#[Fillable(['department_id', 'name', 'description', 'status'])]
class Clinic extends Model
{
    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClinicStatus::class,
        ];
    }
}
