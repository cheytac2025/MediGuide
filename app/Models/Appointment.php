<?php

namespace App\Models;

use App\Casts\EncryptedPatientData;
use App\Enums\AppointmentStatus;
use App\Models\Concerns\ChecksRecordIntegrity;
use App\Models\Concerns\WritesRecordIntegrity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $doctor_id
 * @property int $doctor_schedule_id
 * @property Carbon $appointment_date
 * @property string $start_time
 * @property string $end_time
 * @property string|null $patient_concern
 * @property AppointmentStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient $patient
 * @property-read Doctor $doctor
 * @property-read DoctorSchedule $doctorSchedule
 */
#[Fillable([
    'patient_id',
    'doctor_id',
    'doctor_schedule_id',
    'appointment_date',
    'start_time',
    'end_time',
    'patient_concern',
    'status',
])]
#[Hidden(['patient_concern'])]
class Appointment extends Model
{
    use ChecksRecordIntegrity, WritesRecordIntegrity;

    /**
     * New patient bookings stay pending until hospital staff confirm or reject them.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return BelongsTo<DoctorSchedule, $this>
     */
    public function doctorSchedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
            'patient_concern' => EncryptedPatientData::class,
            'status' => AppointmentStatus::class,
        ];
    }
}
