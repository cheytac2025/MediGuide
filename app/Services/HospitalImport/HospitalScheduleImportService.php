<?php

namespace App\Services\HospitalImport;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Support\Audit\AuditTrailService;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Idempotent hospital schedule import.
 *
 * Dry-run is the default. Live writes require an explicit execute flag.
 * Doctors are created without login accounts. Secretary numbers are not stored.
 * Fixed-hour rows become bookable schedules only when a slot duration is supplied.
 */
class HospitalScheduleImportService
{
    /**
     * @param  list<array<string, mixed>>|null  $doctors
     */
    public function __construct(
        private ?array $doctors = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(?int $bookableSlotMinutes = null, bool $deactivateDevelopment = false): array
    {
        return $this->run(execute: false, bookableSlotMinutes: $bookableSlotMinutes, deactivateDevelopment: $deactivateDevelopment);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?int $bookableSlotMinutes = null, bool $deactivateDevelopment = false): array
    {
        return DB::transaction(function () use ($bookableSlotMinutes, $deactivateDevelopment): array {
            return $this->run(execute: true, bookableSlotMinutes: $bookableSlotMinutes, deactivateDevelopment: $deactivateDevelopment);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function run(bool $execute, ?int $bookableSlotMinutes, bool $deactivateDevelopment): array
    {
        if ($bookableSlotMinutes !== null && $bookableSlotMinutes < 1) {
            throw new InvalidArgumentException('Bookable slot minutes must be a positive integer.');
        }

        $summary = [
            'dry_run' => ! $execute,
            'source' => QueenMaryClinicScheduleCatalog::SOURCE_TITLE,
            'source_note' => QueenMaryClinicScheduleCatalog::SOURCE_NOTE,
            'verification' => 'pending_hospital_verification',
            'bookable_slot_minutes' => $bookableSlotMinutes,
            'departments' => [],
            'clinics' => [],
            'doctors' => [],
            'schedules' => [],
            'appointment_only' => [],
            'ambiguous' => [],
            'skipped' => [],
            'duplicates' => [],
            'development_cleanup' => [],
            'created' => [
                'departments' => 0,
                'clinics' => 0,
                'doctors' => 0,
                'schedules' => 0,
            ],
            'deactivated' => [
                'doctors' => 0,
                'clinics' => 0,
                'departments' => 0,
            ],
        ];

        $department = $this->ensureDepartment($execute, $summary);
        $clinics = [];

        foreach ($this->catalog() as $record) {
            $this->importDoctor($record, $department, $clinics, $execute, $bookableSlotMinutes, $summary);
        }

        if ($deactivateDevelopment) {
            $this->deactivateDevelopmentRecords($execute, $summary);
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        return $this->doctors ?? QueenMaryClinicScheduleCatalog::doctors();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function ensureDepartment(bool $execute, array &$summary): ?Department
    {
        $name = QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME;
        $existing = Department::query()->where('name', $name)->first();

        $row = [
            'name' => $name,
            'description' => QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_DESCRIPTION,
            'mapping' => 'inferred',
            'action' => $existing instanceof Department ? 'reuse' : 'create',
        ];
        $summary['departments'][] = $row;

        if ($existing instanceof Department || ! $execute) {
            return $existing;
        }

        $department = Department::query()->create([
            'name' => $name,
            'description' => QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_DESCRIPTION,
            'status' => DepartmentStatus::Active,
            'data_source' => HospitalDataSource::Imported,
        ]);

        app(AuditTrailService::class)->recordFor(
            AuditAction::DepartmentCreated,
            AuditOutcome::Success,
            $department,
            ['source' => 'hospital_schedule_import'],
        );
        $summary['created']['departments']++;

        return $department;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, Clinic|true>  $clinics
     * @param  array<string, mixed>  $summary
     */
    private function importDoctor(
        array $record,
        ?Department $department,
        array &$clinics,
        bool $execute,
        ?int $bookableSlotMinutes,
        array &$summary,
    ): void {
        $displayName = trim((string) ($record['display_name'] ?? ''));
        $specialization = trim((string) ($record['specialization'] ?? ''));

        if ($displayName === '' || $specialization === '') {
            $summary['skipped'][] = [
                'reason' => 'missing_required_fields',
                'display_name' => $displayName === '' ? null : $displayName,
                'specialization' => $specialization === '' ? null : $specialization,
            ];

            return;
        }

        $clinic = $this->ensureClinic($specialization, $department, $clinics, $execute, $summary);
        $existing = Doctor::query()
            ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($displayName)])
            ->first();

        $bookingMode = (string) ($record['booking_mode'] ?? 'fixed');
        $doctorRow = [
            'display_name' => $displayName,
            'specialization' => $specialization,
            'room_source' => $record['room'] ?? null,
            'booking_mode' => $bookingMode,
            'mapping' => 'inferred_clinic_from_specialization',
            'proposed_status' => 'active',
            'verification' => 'pending_hospital_verification',
            'login_account' => 'not_created',
            'action' => $existing instanceof Doctor ? 'skip_duplicate' : 'create',
            'notes' => $record['notes'] ?? [],
        ];
        $summary['doctors'][] = $doctorRow;

        if (($record['ambiguous'] ?? false) === true) {
            $summary['ambiguous'][] = [
                'display_name' => $displayName,
                'notes' => $record['notes'] ?? [],
            ];
        }

        if (in_array($bookingMode, ['by_appointment', 'strictly_by_appointment'], true)) {
            $summary['appointment_only'][] = [
                'display_name' => $displayName,
                'booking_mode' => $bookingMode,
                'listed_hours' => $this->describeWindows($record['listed_hours'] ?? []),
            ];
        }

        if ($existing instanceof Doctor) {
            $summary['duplicates'][] = [
                'display_name' => $displayName,
                'existing_doctor_id' => $existing->id,
            ];
            $this->importSchedules($execute ? $existing : null, $record, $execute, $bookableSlotMinutes, $summary);

            return;
        }

        if (! $execute || ! $clinic instanceof Clinic) {
            $this->importSchedules(null, $record, false, $bookableSlotMinutes, $summary);

            return;
        }

        $doctor = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => null,
            'display_name' => $displayName,
            'specialization' => $specialization,
            'status' => DoctorStatus::Active,
            'data_source' => HospitalDataSource::Imported,
        ]);

        app(AuditTrailService::class)->recordFor(
            AuditAction::DoctorCreated,
            AuditOutcome::Success,
            $doctor,
            [
                'clinic_id' => $clinic->id,
                'source' => 'hospital_schedule_import',
                'login_account' => false,
            ],
        );
        $summary['created']['doctors']++;

        $this->importSchedules($doctor, $record, $execute, $bookableSlotMinutes, $summary);
    }

    /**
     * @param  array<string, Clinic|true>  $clinics
     * @param  array<string, mixed>  $summary
     */
    private function ensureClinic(
        string $specialization,
        ?Department $department,
        array &$clinics,
        bool $execute,
        array &$summary,
    ): ?Clinic {
        if (array_key_exists($specialization, $clinics)) {
            return $clinics[$specialization] instanceof Clinic ? $clinics[$specialization] : null;
        }

        $existing = null;

        if ($department instanceof Department) {
            $existing = Clinic::query()
                ->where('department_id', $department->id)
                ->where('name', $specialization)
                ->first();
        }

        $summary['clinics'][] = [
            'name' => $specialization,
            'department' => QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME,
            'mapping' => 'inferred_from_specialization',
            'action' => $existing instanceof Clinic ? 'reuse' : 'create',
        ];

        if ($existing instanceof Clinic) {
            $clinics[$specialization] = $existing;

            return $existing;
        }

        if (! $execute || ! $department instanceof Department) {
            $clinics[$specialization] = true;

            return null;
        }

        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => $specialization,
            'description' => 'Imported from the supplied clinic-schedule poster for this specialization. Mapping to an official hospital clinic requires confirmation. Not hospital-verified.',
            'status' => ClinicStatus::Active,
            'data_source' => HospitalDataSource::Imported,
        ]);

        app(AuditTrailService::class)->recordFor(
            AuditAction::ClinicCreated,
            AuditOutcome::Success,
            $clinic,
            ['source' => 'hospital_schedule_import'],
        );
        $summary['created']['clinics']++;
        $clinics[$specialization] = $clinic;

        return $clinic;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $summary
     */
    private function importSchedules(
        ?Doctor $doctor,
        array $record,
        bool $execute,
        ?int $bookableSlotMinutes,
        array &$summary,
    ): void {
        $windows = $record['schedules'] ?? [];

        if (! is_array($windows) || $windows === []) {
            return;
        }

        foreach ($windows as $window) {
            if (! is_array($window)) {
                continue;
            }

            $day = $window['day'] ?? null;
            $start = $window['start'] ?? null;
            $end = $window['end'] ?? null;

            if (! $day instanceof DayOfWeek || ! is_string($start) || ! is_string($end)) {
                continue;
            }

            $action = $bookableSlotMinutes === null
                ? 'hold_pending_slot_duration'
                : 'create';

            $summary['schedules'][] = [
                'doctor' => $record['display_name'],
                'day' => $day->value,
                'start' => $start,
                'end' => $end,
                'slot_duration' => $bookableSlotMinutes,
                'action' => $action,
            ];

            if ($action !== 'create' || ! $execute || ! $doctor instanceof Doctor) {
                continue;
            }

            $exists = DoctorSchedule::query()
                ->where('doctor_id', $doctor->id)
                ->where('day_of_week', $day)
                ->where('start_time', $start.':00')
                ->where('end_time', $end.':00')
                ->exists();

            if ($exists) {
                continue;
            }

            $schedule = DoctorSchedule::query()->create([
                'doctor_id' => $doctor->id,
                'day_of_week' => $day,
                'start_time' => $start.':00',
                'end_time' => $end.':00',
                'slot_duration' => $bookableSlotMinutes,
                'status' => DoctorScheduleStatus::Active,
            ]);

            app(AuditTrailService::class)->recordFor(
                AuditAction::ScheduleCreated,
                AuditOutcome::Success,
                $schedule,
                [
                    'doctor_id' => $doctor->id,
                    'source' => 'hospital_schedule_import',
                ],
            );
            $summary['created']['schedules']++;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function deactivateDevelopmentRecords(bool $execute, array &$summary): void
    {
        $doctors = Doctor::query()
            ->whereIn('display_name', QueenMaryClinicScheduleCatalog::developmentDoctorNames())
            ->get();

        foreach ($doctors as $doctor) {
            $appointments = Appointment::query()->where('doctor_id', $doctor->id)->count();
            $row = [
                'type' => 'doctor',
                'name' => $doctor->display_name,
                'appointments' => $appointments,
                'action' => $doctor->status === DoctorStatus::Inactive ? 'already_inactive' : 'deactivate',
                'reassign_appointments' => false,
            ];
            $summary['development_cleanup'][] = $row;

            if (! $execute || $row['action'] !== 'deactivate') {
                continue;
            }

            $doctor->update(['status' => DoctorStatus::Inactive]);
            app(AuditTrailService::class)->recordFor(
                AuditAction::DoctorStatusChanged,
                AuditOutcome::Success,
                $doctor,
                ['status' => DoctorStatus::Inactive->value, 'source' => 'hospital_schedule_import'],
            );
            $summary['deactivated']['doctors']++;
        }

        $clinics = Clinic::query()
            ->whereIn('name', QueenMaryClinicScheduleCatalog::developmentClinicNames())
            ->get();

        foreach ($clinics as $clinic) {
            $activeDoctors = $clinic->doctors()->where('status', DoctorStatus::Active)->count();
            $row = [
                'type' => 'clinic',
                'name' => $clinic->name,
                'active_doctors' => $activeDoctors,
                'action' => $clinic->status === ClinicStatus::Inactive
                    ? 'already_inactive'
                    : ($activeDoctors > 0 ? 'hold_active_doctors_remain' : 'deactivate'),
            ];
            $summary['development_cleanup'][] = $row;

            if (! $execute || $row['action'] !== 'deactivate') {
                continue;
            }

            $clinic->update(['status' => ClinicStatus::Inactive]);
            app(AuditTrailService::class)->recordFor(
                AuditAction::ClinicStatusChanged,
                AuditOutcome::Success,
                $clinic,
                ['status' => ClinicStatus::Inactive->value, 'source' => 'hospital_schedule_import'],
            );
            $summary['deactivated']['clinics']++;
        }

        $departments = Department::query()
            ->whereIn('name', QueenMaryClinicScheduleCatalog::developmentDepartmentNames())
            ->get();

        foreach ($departments as $department) {
            $activeClinics = $department->clinics()->where('status', ClinicStatus::Active)->count();
            $row = [
                'type' => 'department',
                'name' => $department->name,
                'active_clinics' => $activeClinics,
                'action' => $department->status === DepartmentStatus::Inactive
                    ? 'already_inactive'
                    : ($activeClinics > 0 ? 'hold_active_clinics_remain' : 'deactivate'),
            ];
            $summary['development_cleanup'][] = $row;

            if (! $execute || $row['action'] !== 'deactivate') {
                continue;
            }

            $department->update(['status' => DepartmentStatus::Inactive]);
            app(AuditTrailService::class)->recordFor(
                AuditAction::DepartmentStatusChanged,
                AuditOutcome::Success,
                $department,
                ['status' => DepartmentStatus::Inactive->value, 'source' => 'hospital_schedule_import'],
            );
            $summary['deactivated']['departments']++;
        }
    }

    /**
     * @param  list<mixed>  $windows
     * @return list<string>
     */
    private function describeWindows(array $windows): array
    {
        $described = [];

        foreach ($windows as $window) {
            if (! is_array($window) || ! (($window['day'] ?? null) instanceof DayOfWeek)) {
                continue;
            }

            $described[] = $window['day']->value.' '.$window['start'].'-'.$window['end'];
        }

        return $described;
    }
}
