<?php

namespace App\Http\Controllers\Staff;

use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreDoctorScheduleRequest;
use App\Http\Requests\Staff\UpdateDoctorScheduleRequest;
use App\Http\Requests\Staff\UpdateDoctorScheduleStatusRequest;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\DoctorScheduleManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffDoctorScheduleController extends Controller
{
    public function __construct(
        private readonly DoctorScheduleManagementService $schedules,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $doctors = Doctor::query()
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

        return view('staff.doctor-schedules.show', [
            'staff' => $this->staffHeader($user),
            'active' => 'doctor-schedules',
            'doctorRecord' => $doctor,
            'schedules' => $schedules,
            'editingSchedule' => $editingSchedule,
            'days' => DayOfWeek::cases(),
            'statuses' => DoctorScheduleStatus::cases(),
        ]);
    }

    public function store(StoreDoctorScheduleRequest $request, Doctor $doctor): RedirectResponse
    {
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
