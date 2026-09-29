<?php

namespace App\Http\Controllers\Patient;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\AppointmentSubmittedNotification;
use App\Services\AppointmentBookingService;
use App\Support\PatientHeader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookAppointmentController extends Controller
{
    /**
     * Upcoming dates offered from active doctor schedules, including today.
     */
    private const BOOKING_HORIZON_DAYS = 41;

    public function __construct(private AppointmentBookingService $booking) {}

    /**
     * Clinic and doctor ids in the query string can later be prefilled from
     * the AI front desk. They are validated again on the server.
     */
    public function create(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->query('step') === 'review') {
            return $this->reviewPage($request, $user);
        }

        $error = session('booking_error');
        $clinics = $this->activeClinics();
        $clinic = null;
        $doctors = new Collection;
        $doctor = null;
        $dates = [];
        $date = null;
        $slots = [];
        $slot = null;

        if ($request->filled('clinic_id')) {
            $clinic = $clinics->firstWhere('id', (int) $request->query('clinic_id'));

            if (! $clinic instanceof Clinic) {
                $error = 'The selected clinic is unavailable.';
            }
        }

        if ($clinic instanceof Clinic && $request->filled('doctor_id')) {
            $doctors = $this->activeDoctors($clinic);
            $doctor = $doctors->firstWhere('id', (int) $request->query('doctor_id'));

            if (! $doctor instanceof Doctor) {
                $error = 'The selected doctor is unavailable.';
            }
        } elseif ($clinic instanceof Clinic) {
            $doctors = $this->activeDoctors($clinic);
        }

        if ($doctor instanceof Doctor && $request->filled('appointment_date')) {
            $date = $this->bookableDate($doctor, (string) $request->query('appointment_date'));

            if (! $date instanceof Carbon) {
                $error = 'The selected appointment date is no longer available.';
            }
        }

        if ($doctor instanceof Doctor) {
            $dates = $this->bookableDates($doctor);
        }

        if ($doctor instanceof Doctor && $date instanceof Carbon && $request->filled('start_time')) {
            $slots = $this->slotsFor($doctor, $date);
            $slot = collect($slots)->firstWhere('start', (string) $request->query('start_time'));

            if ($slot === null) {
                $error = 'That time slot is no longer available. Please choose another time.';
            }
        } elseif ($doctor instanceof Doctor && $date instanceof Carbon) {
            $slots = $this->slotsFor($doctor, $date);
        }

        $step = 1;

        if ($clinic instanceof Clinic) {
            $step = 2;
        }

        if ($clinic instanceof Clinic && $doctor instanceof Doctor) {
            $step = 3;
        }

        if ($clinic instanceof Clinic && $doctor instanceof Doctor && $date instanceof Carbon) {
            $step = 4;
        }

        if ($clinic instanceof Clinic && $doctor instanceof Doctor && $date instanceof Carbon && is_array($slot)) {
            $step = 5;
        }

        return view('patient.book-appointment', [
            'patient' => $this->patientHeader($user),
            'active' => 'book',
            'step' => $step,
            'error' => $error,
            'clinics' => $clinics,
            'clinic' => $clinic,
            'doctors' => $doctors,
            'doctor' => $doctor,
            'dates' => $dates,
            'date' => $date,
            'slots' => $slots,
            'slot' => $slot,
            'profile' => $this->profile($user),
            'review' => null,
        ]);
    }

    public function review(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->validate($this->bookingRules());
        $resolved = $this->resolveBooking($user, $input);

        if ($resolved['error'] !== null) {
            return redirect()
                ->route('patient.book-appointment', $this->selectionQuery($input, includeTime: false))
                ->with('booking_error', $resolved['error']);
        }

        session(['patient_booking_draft' => [
            'clinic_id' => $resolved['clinic']->id,
            'doctor_id' => $resolved['doctor']->id,
            'doctor_schedule_id' => $resolved['schedule']->id,
            'appointment_date' => $resolved['date']->toDateString(),
            'start_time' => $resolved['start'],
            'patient_concern' => $resolved['concern'],
        ]]);

        return redirect()->route('patient.book-appointment', ['step' => 'review']);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->validate($this->bookingRules());
        $resolved = $this->resolveBooking($user, $input);

        if ($resolved['error'] !== null) {
            return $this->bookingFailed($input, $resolved['error']);
        }

        try {
            $appointment = $this->booking->book(
                $resolved['patient'],
                $resolved['doctor'],
                $resolved['schedule'],
                $resolved['date']->toDateString(),
                $resolved['start'],
                $resolved['concern'],
            );
        } catch (ValidationException $exception) {
            return $this->bookingFailed($input, $this->friendlyBookingError($exception));
        }

        $user->notify(new AppointmentSubmittedNotification($appointment->loadMissing('doctor')));

        session()->forget('patient_booking_draft');

        return redirect()
            ->route('patient.appointments')
            ->with('appointment_submitted', [
                'message' => 'Your appointment request has been submitted successfully.',
                'date' => $resolved['date']->format('l, F j, Y'),
                'time' => $this->formatTime($resolved['start']),
                'doctor' => $resolved['doctor']->display_name,
                'clinic' => $resolved['clinic']->name,
                'status' => 'Pending',
            ]);
    }

    private function reviewPage(Request $request, User $user): View|RedirectResponse
    {
        $draft = session('patient_booking_draft');

        if (! is_array($draft)) {
            return redirect()->route('patient.book-appointment');
        }

        $resolved = $this->resolveBooking($user, $draft);

        if ($resolved['error'] !== null) {
            session()->forget('patient_booking_draft');

            return redirect()
                ->route('patient.book-appointment')
                ->with('booking_error', $resolved['error']);
        }

        return view('patient.book-appointment', [
            'patient' => $this->patientHeader($user),
            'active' => 'book',
            'step' => 6,
            'error' => session('booking_error'),
            'clinics' => new Collection,
            'clinic' => $resolved['clinic'],
            'doctors' => new Collection,
            'doctor' => $resolved['doctor'],
            'dates' => [],
            'date' => $resolved['date'],
            'slots' => [],
            'slot' => [
                'start' => $resolved['start'],
                'label' => $this->formatTime($resolved['start']),
                'doctor_schedule_id' => $resolved['schedule']->id,
            ],
            'profile' => $this->profile($user),
            'review' => [
                'clinic' => $resolved['clinic']->name,
                'doctor' => $resolved['doctor']->display_name,
                'specialization' => $resolved['doctor']->specialization,
                'date' => $resolved['date']->format('l, F j, Y'),
                'time' => $this->formatTime($resolved['start']),
                'concern' => $resolved['concern'],
                'clinic_id' => $resolved['clinic']->id,
                'doctor_id' => $resolved['doctor']->id,
                'doctor_schedule_id' => $resolved['schedule']->id,
                'appointment_date' => $resolved['date']->toDateString(),
                'start_time' => $resolved['start'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     error: string|null,
     *     patient: Patient|null,
     *     clinic: Clinic|null,
     *     doctor: Doctor|null,
     *     schedule: DoctorSchedule|null,
     *     date: Carbon|null,
     *     start: string|null,
     *     concern: string|null
     * }
     */
    private function resolveBooking(User $user, array $input): array
    {
        $empty = [
            'error' => null,
            'patient' => null,
            'clinic' => null,
            'doctor' => null,
            'schedule' => null,
            'date' => null,
            'start' => null,
            'concern' => null,
        ];

        $patient = $user->patient;

        if (! $patient instanceof Patient) {
            return [...$empty, 'error' => 'A patient profile is required before booking an appointment.'];
        }

        $clinic = Clinic::query()->with('department')->find($input['clinic_id'] ?? null);

        if (
            ! $clinic instanceof Clinic
            || $clinic->status !== ClinicStatus::Active
            || $clinic->department->status !== DepartmentStatus::Active
        ) {
            return [...$empty, 'error' => 'The selected clinic is unavailable.'];
        }

        $doctor = Doctor::query()->find($input['doctor_id'] ?? null);

        if (
            ! $doctor instanceof Doctor
            || $doctor->status !== DoctorStatus::Active
            || (int) $doctor->clinic_id !== (int) $clinic->id
        ) {
            return [...$empty, 'error' => 'The selected doctor is unavailable.'];
        }

        $schedule = DoctorSchedule::query()->find($input['doctor_schedule_id'] ?? null);

        if (
            ! $schedule instanceof DoctorSchedule
            || (int) $schedule->doctor_id !== (int) $doctor->id
            || $schedule->status !== DoctorScheduleStatus::Active
        ) {
            return [...$empty, 'error' => 'Your selected doctor is unavailable for that schedule.'];
        }

        $date = $this->parseDate((string) ($input['appointment_date'] ?? ''));
        $start = $this->normalizeStart((string) ($input['start_time'] ?? ''));

        if (! $date instanceof Carbon || strtolower($date->format('l')) !== $schedule->day_of_week->value) {
            return [...$empty, 'error' => 'The selected appointment date is no longer available.'];
        }

        if ($date->toDateString() < now()->toDateString()) {
            return [...$empty, 'error' => 'The selected appointment date is no longer available.'];
        }

        $available = $this->booking->getAvailableSlots($doctor, $schedule, $date->toDateString());

        if ($available === []) {
            return [...$empty, 'error' => 'The selected appointment date is no longer available.'];
        }

        if ($start === null || ! in_array($start, $available, true)) {
            return [...$empty, 'error' => 'That time slot is no longer available. Please choose another time.'];
        }

        $concern = isset($input['patient_concern']) ? trim((string) $input['patient_concern']) : '';

        return [
            'error' => null,
            'patient' => $patient,
            'clinic' => $clinic,
            'doctor' => $doctor,
            'schedule' => $schedule,
            'date' => $date,
            'start' => $start,
            'concern' => $concern === '' ? null : $concern,
        ];
    }

    /**
     * @return Collection<int, Clinic>
     */
    private function activeClinics(): Collection
    {
        return Clinic::query()
            ->with('department')
            ->where('status', ClinicStatus::Active)
            ->whereHas('department', fn ($query) => $query->where('status', DepartmentStatus::Active))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Doctor>
     */
    private function activeDoctors(Clinic $clinic): Collection
    {
        return $clinic->doctors()
            ->where('status', DoctorStatus::Active)
            ->orderBy('display_name')
            ->get();
    }

    /**
     * @return list<Carbon>
     */
    private function bookableDates(Doctor $doctor): array
    {
        $schedules = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('status', DoctorScheduleStatus::Active)
            ->get();

        $dates = [];
        $cursor = now()->startOfDay();
        $last = now()->startOfDay()->addDays(self::BOOKING_HORIZON_DAYS);

        while ($cursor->lte($last)) {
            $weekday = strtolower($cursor->format('l'));

            foreach ($schedules as $schedule) {
                if ($schedule->day_of_week->value !== $weekday) {
                    continue;
                }

                if ($this->booking->getAvailableSlots($doctor, $schedule, $cursor->toDateString()) !== []) {
                    $dates[] = $cursor;
                    break;
                }
            }

            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    private function bookableDate(Doctor $doctor, string $value): ?Carbon
    {
        $date = $this->parseDate($value);

        if (! $date instanceof Carbon) {
            return null;
        }

        foreach ($this->bookableDates($doctor) as $bookable) {
            if ($bookable->toDateString() === $date->toDateString()) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @return list<array{start: string, label: string, doctor_schedule_id: int}>
     */
    private function slotsFor(Doctor $doctor, Carbon $date): array
    {
        $weekday = strtolower($date->format('l'));
        $schedules = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('status', DoctorScheduleStatus::Active)
            ->get()
            ->filter(fn (DoctorSchedule $schedule): bool => $schedule->day_of_week->value === $weekday);

        $slots = [];

        foreach ($schedules as $schedule) {
            foreach ($this->booking->getAvailableSlots($doctor, $schedule, $date->toDateString()) as $start) {
                $slots[$start] = [
                    'start' => $start,
                    'label' => $this->formatTime($start),
                    'doctor_schedule_id' => $schedule->id,
                ];
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingRules(): array
    {
        return [
            'clinic_id' => ['required', 'integer'],
            'doctor_id' => ['required', 'integer'],
            'doctor_schedule_id' => ['required', 'integer'],
            'appointment_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'patient_concern' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function selectionQuery(array $input, bool $includeTime): array
    {
        $query = [
            'clinic_id' => $input['clinic_id'] ?? null,
            'doctor_id' => $input['doctor_id'] ?? null,
            'appointment_date' => $input['appointment_date'] ?? null,
        ];

        if ($includeTime) {
            $query['start_time'] = $input['start_time'] ?? null;
        }

        return array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function bookingFailed(array $input, string $message): RedirectResponse
    {
        return redirect()
            ->route('patient.book-appointment', $this->selectionQuery($input, includeTime: false))
            ->with('booking_error', $message);
    }

    private function friendlyBookingError(ValidationException $exception): string
    {
        $errors = $exception->errors();

        if (isset($errors['patient_id'])) {
            return 'You already have an appointment that overlaps this time. Please choose another time.';
        }

        if (isset($errors['start_time'])) {
            return 'That time slot is no longer available. Please choose another time.';
        }

        if (isset($errors['appointment_date'])) {
            return 'The selected appointment date is no longer available.';
        }

        if (isset($errors['doctor_schedule_id']) || isset($errors['doctor_id'])) {
            return 'Your selected doctor is unavailable for that schedule.';
        }

        if (isset($errors['clinic']) || isset($errors['department'])) {
            return 'The selected clinic is unavailable.';
        }

        return 'Your appointment could not be booked. Please review your selection and try again.';
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        if (! $parsed instanceof Carbon || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed->startOfDay();
    }

    private function normalizeStart(string $value): ?string
    {
        try {
            $parsed = Carbon::createFromFormat('!H:i', $value);
        } catch (\Throwable) {
            return null;
        }

        if (! $parsed instanceof Carbon || $parsed->format('H:i') !== $value) {
            return null;
        }

        return $parsed->format('H:i');
    }

    private function formatTime(string $start): string
    {
        return Carbon::createFromFormat('!H:i', $start)->format('h:i A');
    }

    private function patientHeader(User $user): array
    {
        return PatientHeader::from($user);
    }

    /**
     * @return array{name: string, email: string, contact: string|null}
     */
    private function profile(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'contact' => $user->patient?->contact_number,
        ];
    }
}
