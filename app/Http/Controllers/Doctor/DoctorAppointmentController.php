<?php

namespace App\Http\Controllers\Doctor;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Doctor\Concerns\InteractsWithLinkedDoctor;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use App\Support\DoctorHeader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DoctorAppointmentController extends Controller
{
    use InteractsWithLinkedDoctor;

    public function index(Request $request): View|RedirectResponse
    {
        $doctor = $this->linkedDoctor($request);

        if ($doctor instanceof RedirectResponse) {
            return $doctor;
        }

        /** @var User $user */
        $user = $request->user();
        $filter = $this->resolvedFilter($request->query('filter'));
        $today = Carbon::today();

        $appointments = Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->with(['patient.user', 'doctor.clinic']);

        $this->applyFilter($appointments, $filter, $today);
        $this->applyOrdering($appointments, $filter);

        return view('doctor.appointments', [
            'doctor' => DoctorHeader::from($user, $doctor),
            'active' => 'appointments',
            'appointments' => $appointments->get(),
            'filter' => $filter,
            'emptyMessage' => $this->emptyMessage($filter),
        ]);
    }

    public function show(Request $request, int $appointment): View|RedirectResponse
    {
        $doctor = $this->linkedDoctor($request);

        if ($doctor instanceof RedirectResponse) {
            return $doctor;
        }

        /** @var User $user */
        $user = $request->user();

        $record = Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->with(['patient.user', 'doctor.clinic.department'])
            ->whereKey($appointment)
            ->firstOrFail();

        return view('doctor.appointment-show', [
            'doctor' => DoctorHeader::from($user, $doctor),
            'active' => 'appointments',
            'appointment' => $record,
        ]);
    }

    private function resolvedFilter(mixed $value): string
    {
        $allowed = ['upcoming', 'completed', 'cancelled', 'rejected'];

        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }

        return 'upcoming';
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    private function applyFilter(Builder $query, string $filter, Carbon $today): void
    {
        match ($filter) {
            'upcoming' => $query
                ->where('status', AppointmentStatus::Confirmed)
                ->whereDate('appointment_date', '>=', $today),
            'completed' => $query->where('status', AppointmentStatus::Completed),
            'cancelled' => $query->where('status', AppointmentStatus::Cancelled),
            'rejected' => $query->where('status', AppointmentStatus::Rejected),
        };
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    private function applyOrdering(Builder $query, string $filter): void
    {
        if ($filter === 'upcoming') {
            $query->orderBy('appointment_date')->orderBy('start_time');

            return;
        }

        $query->orderByDesc('appointment_date')->orderByDesc('start_time');
    }

    private function emptyMessage(string $filter): string
    {
        return match ($filter) {
            'upcoming' => 'No upcoming confirmed appointments.',
            'completed' => 'No completed appointments.',
            'cancelled' => 'No cancelled appointments.',
            'rejected' => 'No rejected appointments.',
            default => 'No appointments.',
        };
    }
}
