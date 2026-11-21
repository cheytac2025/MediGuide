<?php

namespace App\Services\Demo;

use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Services\AppointmentBookingService;
use App\Support\Demo\DemoBookingEnvironment;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use App\Support\Scheduling\AppointmentOnlyDoctors;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ensures every eligible active doctor has a future demonstration slot on an
 * isolated demo database. Creates only provisional_demonstration windows.
 * Never modifies approved (non-provisional) schedules.
 */
final class DemoBookingCoverageService
{
    public const int SLOT_DURATION_MINUTES = 30;

    public function __construct(
        private readonly AppointmentBookingService $booking,
    ) {}

    /**
     * @return array{
     *     inventory: list<array<string, mixed>>,
     *     created_schedules: int,
     *     already_bookable: int,
     *     made_bookable: int,
     *     skipped_inactive_scope: int,
     *     still_unbookable: list<array{id:int,name:string,reason:string}>,
     *     eligible_active: int,
     *     bookable_after: int
     * }
     */
    public function ensureCoverage(bool $dryRun = false): array
    {
        if (! DemoBookingEnvironment::isIsolatedProcess()) {
            throw new RuntimeException('Demo booking coverage is only available in an isolated demonstration process.');
        }

        if (DemoBookingEnvironment::isBlockedDatabase()) {
            throw new RuntimeException('Demo booking coverage refuses blocked production/shared databases.');
        }

        $inventory = [];
        $created = 0;
        $already = 0;
        $made = 0;
        $skippedScope = 0;
        $still = [];

        $doctors = $this->activeDoctors();

        foreach ($doctors as $doctor) {
            $row = $this->inventoryRow($doctor);
            $inventory[] = $row;

            if (! $row['eligible_scope']) {
                $skippedScope++;

                continue;
            }

            if ($row['bookable']) {
                $already++;

                continue;
            }

            $windows = $this->proposedWindows($doctor);

            if ($windows === []) {
                $still[] = [
                    'id' => $doctor->id,
                    'name' => $doctor->display_name,
                    'reason' => 'no_safe_demonstration_window',
                ];

                continue;
            }

            if ($dryRun) {
                $made++;

                continue;
            }

            $createdForDoctor = $this->applyWindows($doctor, $windows);
            $created += $createdForDoctor;

            $doctor->refresh()->load(['clinic.department', 'schedules']);
            $slot = $this->booking->nextAvailableSlot($doctor);

            if ($slot === null) {
                $still[] = [
                    'id' => $doctor->id,
                    'name' => $doctor->display_name,
                    'reason' => 'slot_still_missing_after_write',
                ];

                continue;
            }

            $made++;
        }

        $eligible = collect($inventory)->where('eligible_scope', true)->count();
        $bookableAfter = $dryRun
            ? ($already + $made)
            : Doctor::query()
                ->where('status', DoctorStatus::Active)
                ->with(['clinic.department', 'schedules'])
                ->get()
                ->filter(fn (Doctor $doctor): bool => $this->eligibleScope($doctor))
                ->filter(fn (Doctor $doctor): bool => $this->booking->nextAvailableSlot($doctor) !== null)
                ->count();

        return [
            'inventory' => $inventory,
            'created_schedules' => $created,
            'already_bookable' => $already,
            'made_bookable' => $made,
            'skipped_inactive_scope' => $skippedScope,
            'still_unbookable' => $still,
            'eligible_active' => $eligible,
            'bookable_after' => $bookableAfter,
        ];
    }

    /**
     * @return Collection<int, Doctor>
     */
    public function activeDoctors(): Collection
    {
        return Doctor::query()
            ->with(['clinic.department', 'schedules'])
            ->where('status', DoctorStatus::Active)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function inventoryRow(Doctor $doctor): array
    {
        $active = $doctor->schedules->filter(
            fn (DoctorSchedule $schedule): bool => $schedule->status === DoctorScheduleStatus::Active,
        );
        $provisional = $active->filter(fn (DoctorSchedule $s): bool => (bool) $s->provisional_demonstration);
        $approved = $active->filter(fn (DoctorSchedule $s): bool => ! $s->provisional_demonstration);
        $eligible = $this->eligibleScope($doctor);
        $slot = $eligible ? $this->booking->nextAvailableSlot($doctor) : null;

        return [
            'id' => $doctor->id,
            'name' => $doctor->display_name,
            'specialization' => $doctor->specialization,
            'clinic_id' => $doctor->clinic_id,
            'clinic' => $doctor->clinic?->name,
            'department' => $doctor->clinic?->department?->name,
            'status' => $doctor->status->value,
            'eligible_scope' => $eligible,
            'appointment_only' => AppointmentOnlyDoctors::excludes($doctor),
            'active_schedules' => $active->count(),
            'approved_schedules' => $approved->count(),
            'provisional_schedules' => $provisional->count(),
            'bookable' => $slot !== null,
            'next' => $slot === null ? null : ($slot['date'].' '.$slot['time']),
            'next_provisional' => (bool) ($slot['provisional'] ?? false),
            'missing_demo_availability' => $eligible && $slot === null,
        ];
    }

    private function eligibleScope(Doctor $doctor): bool
    {
        $clinic = $doctor->clinic;
        $department = $clinic?->department;

        return $doctor->status === DoctorStatus::Active
            && $clinic !== null
            && $clinic->status === ClinicStatus::Active
            && $department !== null
            && $department->status === DepartmentStatus::Active;
    }

    /**
     * @return list<array{day_of_week: DayOfWeek, start_time: string, end_time: string}>
     */
    private function proposedWindows(Doctor $doctor): array
    {
        $fromCatalog = $this->catalogWindows($doctor);

        if ($fromCatalog !== []) {
            return $this->filterNonOverlapping($doctor, $fromCatalog);
        }

        // Synthetic demonstration windows for doctors with no poster hours.
        // Explicitly provisional — not official hospital schedules.
        $fallback = [
            [
                'day_of_week' => DayOfWeek::Monday,
                'start_time' => '09:00:00',
                'end_time' => '11:00:00',
            ],
            [
                'day_of_week' => DayOfWeek::Wednesday,
                'start_time' => '09:00:00',
                'end_time' => '11:00:00',
            ],
            [
                'day_of_week' => DayOfWeek::Friday,
                'start_time' => '14:00:00',
                'end_time' => '16:00:00',
            ],
        ];

        return $this->filterNonOverlapping($doctor, $fallback);
    }

    /**
     * @return list<array{day_of_week: DayOfWeek, start_time: string, end_time: string}>
     */
    private function catalogWindows(Doctor $doctor): array
    {
        $name = mb_strtolower(trim($doctor->display_name));

        foreach (QueenMaryClinicScheduleCatalog::doctors() as $record) {
            $catalogName = mb_strtolower(trim((string) ($record['display_name'] ?? '')));

            if ($catalogName !== $name) {
                continue;
            }

            $sources = [];

            foreach (['schedules', 'listed_hours'] as $key) {
                foreach ($record[$key] ?? [] as $window) {
                    $day = $window['day'] ?? null;

                    if (! $day instanceof DayOfWeek) {
                        continue;
                    }

                    $sources[] = [
                        'day_of_week' => $day,
                        'start_time' => $this->normalizeTime((string) ($window['start'] ?? '')),
                        'end_time' => $this->normalizeTime((string) ($window['end'] ?? '')),
                    ];
                }
            }

            return $sources;
        }

        return [];
    }

    /**
     * @param  list<array{day_of_week: DayOfWeek, start_time: string, end_time: string}>  $windows
     * @return list<array{day_of_week: DayOfWeek, start_time: string, end_time: string}>
     */
    private function filterNonOverlapping(Doctor $doctor, array $windows): array
    {
        $accepted = [];

        foreach ($windows as $window) {
            if ($window['start_time'] >= $window['end_time']) {
                continue;
            }

            $overlapsExisting = $doctor->schedules
                ->filter(fn (DoctorSchedule $s): bool => $s->status === DoctorScheduleStatus::Active)
                ->contains(function (DoctorSchedule $schedule) use ($window): bool {
                    return $schedule->day_of_week === $window['day_of_week']
                        && $schedule->start_time < $window['end_time']
                        && $schedule->end_time > $window['start_time'];
                });

            if ($overlapsExisting) {
                continue;
            }

            $accepted[] = $window;
        }

        return $accepted;
    }

    /**
     * @param  list<array{day_of_week: DayOfWeek, start_time: string, end_time: string}>  $windows
     */
    private function applyWindows(Doctor $doctor, array $windows): int
    {
        return (int) DB::transaction(function () use ($doctor, $windows): int {
            $created = 0;

            foreach ($windows as $window) {
                DoctorSchedule::query()->create([
                    'doctor_id' => $doctor->id,
                    'day_of_week' => $window['day_of_week'],
                    'start_time' => $window['start_time'],
                    'end_time' => $window['end_time'],
                    'slot_duration' => self::SLOT_DURATION_MINUTES,
                    'status' => DoctorScheduleStatus::Active,
                    'provisional_demonstration' => true,
                ]);
                $created++;
            }

            return $created;
        });
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);

        if (strlen($time) === 5) {
            return $time.':00';
        }

        return $time;
    }
}
