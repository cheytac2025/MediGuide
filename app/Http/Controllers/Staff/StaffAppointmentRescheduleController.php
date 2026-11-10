<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreAppointmentRescheduleRequest;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\HospitalStaffScope;
use App\Support\Scheduling\MonthCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffAppointmentRescheduleController extends Controller
{
    public function __construct(
        private readonly HospitalStaffScope $scope,
        private readonly AppointmentBookingService $booking,
        private readonly MonthCalendar $calendar,
    ) {}

    public function create(Request $request, int $appointment): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->scope->appointment($user, $appointment);
        $record->load(['patient.user', 'doctor.clinic.department', 'doctorSchedule']);

        if (! $record->status->canStaffReschedule() || ! $record->integrityIsValid()) {
            return redirect()
                ->route('staff.appointments.show', $record)
                ->with('appointment_error', 'This appointment can no longer be rescheduled.');
        }

        $doctor = $record->doctor;
        $selected = $this->selectedDate($request, $record->appointment_date->toDateString());
        $open = $selected === null ? [] : $this->booking->openSlots($doctor, $selected, $record->id);
        $defined = $selected === null ? [] : $this->booking->definedSlotChoices($doctor, $selected);
        $openStarts = array_flip(array_column($open, 'start'));
        $choices = [];

        foreach ($defined as $slot) {
            $choices[] = [
                'start' => $slot['start'],
                'label' => Carbon::parse($slot['start'])->format('h:i A'),
                'available' => isset($openStarts[$slot['start']]),
                'schedule_id' => $slot['schedule_id'],
            ];
        }

        $picked = null;
        $pickedStart = is_string($request->query('start_time')) ? $request->query('start_time') : null;

        foreach ($open as $slot) {
            if ($pickedStart === $slot['start']) {
                $picked = $slot;
                break;
            }
        }

        return view('staff.appointment-reschedule', [
            'staff' => $this->staffHeader($user),
            'active' => 'appointments',
            'appointment' => $record,
            'calendar' => $this->month($request, $doctor, $record->id, $selected),
            'selectedDate' => $selected,
            'choices' => $choices,
            'picked' => $picked,
            'sameSlot' => $picked !== null
                && $selected === $record->appointment_date->toDateString()
                && $picked['start'] === Carbon::parse((string) $record->start_time)->format('H:i'),
        ]);
    }

    public function store(StoreAppointmentRescheduleRequest $request, int $appointment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->scope->appointment($user, $appointment);

        if (! $record->status->canStaffReschedule() || ! $record->integrityIsValid()) {
            return redirect()
                ->route('staff.appointments.show', $record)
                ->with('appointment_error', 'This appointment can no longer be rescheduled.');
        }

        $schedule = DoctorSchedule::query()->find($request->integer('doctor_schedule_id'));

        if (! $schedule instanceof DoctorSchedule || (int) $schedule->doctor_id !== (int) $record->doctor_id) {
            return redirect()
                ->route('staff.appointments.reschedule', $record)
                ->withErrors(['start_time' => 'Choose an available time.']);
        }

        try {
            $updated = $this->booking->reschedule(
                $record,
                $schedule,
                (string) $request->validated('appointment_date'),
                (string) $request->validated('start_time'),
                (string) $request->validated('reason'),
                $user,
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('staff.appointments.reschedule', [
                    'appointment' => $record,
                    'date' => $request->validated('appointment_date'),
                    'start_time' => $request->validated('start_time'),
                ])
                ->withInput()
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('staff.appointments.show', $updated)
            ->with('appointment_status', 'Appointment rescheduled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function month(Request $request, \App\Models\Doctor $doctor, int $appointmentId, ?string $selected): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $last = CarbonImmutable::parse($this->booking->lastBookableDate()->toDateString());
        $month = $this->parseMonth(is_string($request->query('month')) ? $request->query('month') : '');

        if (! $month instanceof CarbonImmutable) {
            $month = CarbonImmutable::parse(($selected ?? $today->toDateString()))->startOfMonth();
        }

        if ($month->lt($today->startOfMonth()) || $month->gt($last->startOfMonth())) {
            $month = $today->startOfMonth();
        }

        $selectable = [];
        $cursor = $today;

        while ($cursor->lte($last)) {
            if ($this->booking->openSlots($doctor, $cursor->toDateString(), $appointmentId) !== []) {
                $selectable[$cursor->toDateString()] = true;
            }

            $cursor = $cursor->addDay();
        }

        $calendar = $this->calendar->build(
            $month,
            $selected,
            $today->startOfMonth(),
            $last->startOfMonth(),
            function (array $day) use ($appointmentId, $selectable): array {
                $available = isset($selectable[$day['date']]);

                return [
                    ...$day,
                    'available' => $available,
                    'href' => $available
                        ? route('staff.appointments.reschedule', [
                            'appointment' => $appointmentId,
                            'date' => $day['date'],
                            'month' => substr($day['date'], 0, 7),
                        ])
                        : null,
                    'count' => 0,
                    'marker' => '',
                ];
            },
        );

        $calendar['prevUrl'] = is_string($calendar['prev'])
            ? route('staff.appointments.reschedule', ['appointment' => $appointmentId, 'month' => $calendar['prev']])
            : null;
        $calendar['nextUrl'] = is_string($calendar['next'])
            ? route('staff.appointments.reschedule', ['appointment' => $appointmentId, 'month' => $calendar['next']])
            : null;

        return $calendar;
    }

    private function selectedDate(Request $request, string $current): ?string
    {
        $value = $request->query('date');

        if (! is_string($value) || $value === '') {
            return $current;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (\Throwable) {
            return $current;
        }

        if (! $date instanceof Carbon || $date->format('Y-m-d') !== $value) {
            return $current;
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $last = $this->booking->lastBookableDate()->toDateString();

        if ($value < $today || $value > $last) {
            return $current;
        }

        return $value;
    }

    private function parseMonth(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m', $value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m') !== $value) {
            return null;
        }

        return $parsed->startOfMonth();
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
