<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreDoctorDateOverrideRequest;
use App\Http\Requests\Staff\StoreDoctorScheduleRequest;
use App\Http\Requests\Staff\UpdateDoctorScheduleRequest;
use App\Http\Requests\Staff\UpdateDoctorScheduleStatusRequest;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorDateOverride;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\DoctorScheduleManagementService;
use App\Services\HospitalStaffScope;
use App\Services\Scheduling\DoctorDateOverrideService;
use App\Services\Scheduling\EffectiveScheduleService;
use App\Support\Scheduling\AppointmentOnlyDoctors;
use App\Support\Scheduling\MonthCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffDoctorScheduleController extends Controller
{
    public function __construct(
        private readonly DoctorScheduleManagementService $schedules,
        private readonly HospitalStaffScope $scope,
        private readonly DoctorDateOverrideService $overrides,
        private readonly EffectiveScheduleService $effective,
        private readonly MonthCalendar $calendar,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $doctors = $this->scope->doctors($user)
            ->where('status', DoctorStatus::Active)
            ->with(['clinic.department'])
            ->withCount([
                'schedules as active_schedules_count' => fn ($query) => $query
                    ->where('status', DoctorScheduleStatus::Active),
            ])
            ->orderBy('display_name')
            ->get();

        return view('staff.doctor-schedules.index', [
            'staff' => $this->staffHeader($user),
            'active' => 'doctor-schedules',
            'doctors' => $doctors,
        ]);
    }

    public function show(Request $request, Doctor $doctor): View
    {
        /** @var User $user */
        $user = $request->user();
        $this->scope->ensureDoctor($user, $doctor);

        $doctor->load(['clinic.department']);

        $schedules = $doctor->schedules()
            ->get()
            ->sortBy([
                fn (DoctorSchedule $schedule) => $this->daySortOrder($schedule->day_of_week),
                fn (DoctorSchedule $schedule) => (string) $schedule->start_time,
            ])
            ->values();

        $editingSchedule = null;
        $editId = $request->query('edit');

        if (is_numeric($editId)) {
            $editingSchedule = $schedules->firstWhere('id', (int) $editId);
        }

        $selected = $this->selectedDate($request);
        $month = $this->selectedMonth($request, $selected);
        $day = $this->effective->forDate($doctor, $selected);
        $history = DoctorDateOverride::query()
            ->where('doctor_id', $doctor->id)
            ->whereDate('override_date', $selected->toDateString())
            ->whereNotNull('removed_at')
            ->with(['windows', 'createdBy'])
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return view('staff.doctor-schedules.show', [
            'staff' => $this->staffHeader($user),
            'active' => 'doctor-schedules',
            'doctorRecord' => $doctor,
            'schedules' => $schedules,
            'editingSchedule' => $editingSchedule,
            'days' => DayOfWeek::cases(),
            'statuses' => DoctorScheduleStatus::cases(),
            'selectedDate' => $selected,
            'selectedWeekday' => strtolower($selected->format('l')),
            'calendar' => $this->monthCalendar($doctor, $month, $selected),
            'effectiveDay' => $day,
            'bookedCount' => $this->bookedCount($doctor, $selected),
            'overrideHistory' => $history,
            'appointmentOnly' => AppointmentOnlyDoctors::excludes($doctor),
        ]);
    }

    public function store(StoreDoctorScheduleRequest $request, Doctor $doctor): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->scope->ensureDoctor($user, $doctor);

        try {
            $this->schedules->create($doctor, $request->scheduleAttributes());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('staff.doctor-schedules.show', $doctor)
                ->withInput()
                ->withErrors($exception->errors())
                ->with('schedule_error', collect($exception->errors())->flatten()->first());
        }

        return redirect()
            ->route('staff.doctor-schedules.show', $doctor)
            ->with('schedule_status', 'Doctor schedule added successfully.');
    }

    public function update(UpdateDoctorScheduleRequest $request, DoctorSchedule $doctorSchedule): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->scope->ensureSchedule($user, $doctorSchedule);

        $doctor = $doctorSchedule->doctor;

        try {
            $this->schedules->update($doctorSchedule, $request->scheduleAttributes());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('staff.doctor-schedules.show', [
                    'doctor' => $doctor,
                    'edit' => $doctorSchedule->id,
                ])
                ->withInput()
                ->withErrors($exception->errors())
                ->with('schedule_error', collect($exception->errors())->flatten()->first());
        }

        return redirect()
            ->route('staff.doctor-schedules.show', $doctor)
            ->with('schedule_status', 'Doctor schedule updated successfully.');
    }

    public function updateStatus(UpdateDoctorScheduleStatusRequest $request, DoctorSchedule $doctorSchedule): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->scope->ensureSchedule($user, $doctorSchedule);

        $doctor = $doctorSchedule->doctor;
        $status = DoctorScheduleStatus::from((string) $request->validated('status'));

        try {
            $this->schedules->updateStatus($doctorSchedule, $status);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('staff.doctor-schedules.show', $doctor)
                ->withErrors($exception->errors())
                ->with('schedule_error', collect($exception->errors())->flatten()->first());
        }

        $message = $status === DoctorScheduleStatus::Active
            ? 'Doctor schedule activated successfully.'
            : 'Doctor schedule deactivated successfully.';

        return redirect()
            ->route('staff.doctor-schedules.show', $doctor)
            ->with('schedule_status', $message);
    }

    public function storeOverride(StoreDoctorDateOverrideRequest $request, Doctor $doctor): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->scope->ensureDoctor($user, $doctor);
        $date = (string) $request->validated('date');

        try {
            $this->overrides->save(
                $doctor,
                $date,
                (bool) $request->validated('unavailable'),
                $request->windows(),
                $user,
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('staff.doctor-schedules.show', ['doctor' => $doctor, 'date' => $date])
                ->withInput()
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('staff.doctor-schedules.show', ['doctor' => $doctor, 'date' => $date])
            ->with('schedule_status', 'The hours for this date were saved. Weekly hours were not changed.');
    }

    public function destroyOverride(Request $request, Doctor $doctor): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->scope->ensureDoctor($user, $doctor);
        $date = (string) $request->input('date');

        try {
            $this->overrides->remove($doctor, $date, $user);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('staff.doctor-schedules.show', ['doctor' => $doctor, 'date' => $date])
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('staff.doctor-schedules.show', ['doctor' => $doctor, 'date' => $date])
            ->with('schedule_status', 'This date now uses the weekly schedule again.');
    }

    private function daySortOrder(DayOfWeek $day): int
    {
        return match ($day) {
            DayOfWeek::Monday => 1,
            DayOfWeek::Tuesday => 2,
            DayOfWeek::Wednesday => 3,
            DayOfWeek::Thursday => 4,
            DayOfWeek::Friday => 5,
            DayOfWeek::Saturday => 6,
            DayOfWeek::Sunday => 7,
        };
    }

    private function selectedDate(Request $request): Carbon
    {
        $value = $request->query('date', $request->old('date'));
        $today = Carbon::now(config('app.timezone'))->startOfDay();

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $today;
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (\Throwable) {
            return $today;
        }

        return $parsed instanceof Carbon && $parsed->format('Y-m-d') === $value
            ? $parsed->startOfDay()
            : $today;
    }

    private function selectedMonth(Request $request, Carbon $selected): CarbonImmutable
    {
        $value = $request->query('month');

        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m', $value, config('app.timezone'));
            } catch (\Throwable) {
                $parsed = false;
            }

            if ($parsed instanceof CarbonImmutable && $parsed->format('Y-m') === $value) {
                return $parsed->startOfMonth();
            }
        }

        return CarbonImmutable::parse($selected->toDateString())->startOfMonth();
    }

    /**
     * @return array<string, mixed>
     */
    private function monthCalendar(Doctor $doctor, CarbonImmutable $month, Carbon $selected): array
    {
        $start = $month->startOfMonth()->toDateString();
        $end = $month->endOfMonth()->toDateString();
        $overrides = DoctorDateOverride::query()
            ->where('doctor_id', $doctor->id)
            ->whereNull('removed_at')
            ->whereDate('override_date', '>=', $start)
            ->whereDate('override_date', '<=', $end)
            ->get()
            ->keyBy(fn (DoctorDateOverride $override): string => $override->override_date->toDateString());
        $counts = Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
            ->whereDate('appointment_date', '>=', $start)
            ->whereDate('appointment_date', '<=', $end)
            ->get()
            ->groupBy(fn (Appointment $appointment): string => $appointment->appointment_date->toDateString())
            ->map->count();

        $calendar = $this->calendar->build(
            $month,
            $selected->toDateString(),
            null,
            null,
            function (array $day) use ($doctor, $overrides, $counts): array {
                $override = $overrides->get($day['date']);
                $marker = '';

                if ($override instanceof DoctorDateOverride) {
                    $marker = $override->unavailable ? 'is-off' : 'is-override';
                }

                $count = (int) ($counts[$day['date']] ?? 0);
                $suffix = trim(($marker === 'is-off' ? 'day off' : ($marker === 'is-override' ? 'date-specific hours' : '')).($count > 0 ? ', '.$count.' booked' : ''));

                return [
                    ...$day,
                    'href' => route('staff.doctor-schedules.show', [
                        'doctor' => $doctor,
                        'date' => $day['date'],
                        'month' => substr($day['date'], 0, 7),
                    ]),
                    'count' => $count,
                    'marker' => $marker,
                    'label' => $day['label'].($suffix !== '' ? ', '.$suffix : ''),
                ];
            },
        );

        $calendar['prevUrl'] = is_string($calendar['prev'])
            ? route('staff.doctor-schedules.show', ['doctor' => $doctor, 'month' => $calendar['prev'], 'date' => $selected->toDateString()])
            : null;
        $calendar['nextUrl'] = is_string($calendar['next'])
            ? route('staff.doctor-schedules.show', ['doctor' => $doctor, 'month' => $calendar['next'], 'date' => $selected->toDateString()])
            : null;

        return $calendar;
    }

    private function bookedCount(Doctor $doctor, Carbon $date): int
    {
        return Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
            ->count();
    }

    /**
     * @return array{name: string, first_name: string, role: string, initials: string, unread_notifications: int}
     */
    private function staffHeader(User $user): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name ?: $user->name,
            'role' => 'Hospital Staff',
            'initials' => $user->initials(),
            'unread_notifications' => 0,
        ];
    }
}
