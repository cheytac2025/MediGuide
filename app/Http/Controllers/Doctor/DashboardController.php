<?php

namespace App\Http\Controllers\Doctor;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Doctor\Concerns\InteractsWithLinkedDoctor;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Support\DoctorHeader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use InteractsWithLinkedDoctor;

    public function __invoke(Request $request): View|RedirectResponse
    {
        $doctor = $this->linkedDoctor($request);

        if ($doctor instanceof RedirectResponse) {
            return $doctor;
        }

        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();
        $todayWeekday = DayOfWeek::from(strtolower($today->englishDayOfWeek));

        $appointmentsQuery = Appointment::query()->where('doctor_id', $doctor->id);

        return view('doctor.dashboard', [
            'doctor' => DoctorHeader::from($user, $doctor),
            'active' => 'dashboard',
            'todaysCount' => (clone $appointmentsQuery)
                ->where('status', AppointmentStatus::Confirmed)
                ->whereDate('appointment_date', $today)
                ->count(),
            'upcomingCount' => (clone $appointmentsQuery)
                ->where('status', AppointmentStatus::Confirmed)
                ->whereDate('appointment_date', '>', $today)
                ->count(),
            'completedCount' => (clone $appointmentsQuery)
                ->where('status', AppointmentStatus::Completed)
                ->count(),
            'todaysSchedules' => $this->todaysSchedules($doctor, $todayWeekday),
            'todaysAppointments' => $this->todaysAppointments($doctor, $today),
            'upcomingAppointments' => $this->upcomingAppointments($doctor, $today),
        ]);
    }

    /**
     * @return Collection<int, DoctorSchedule>
     */
    private function todaysSchedules(Doctor $doctor, DayOfWeek $weekday): Collection
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', $weekday)
            ->where('status', DoctorScheduleStatus::Active)
            ->orderBy('start_time')
            ->get();
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function todaysAppointments(Doctor $doctor, Carbon $today): Collection
    {
        return Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->where('status', AppointmentStatus::Confirmed)
            ->whereDate('appointment_date', $today)
            ->with(['patient.user', 'doctor.clinic'])
            ->orderBy('start_time')
            ->get();
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function upcomingAppointments(Doctor $doctor, Carbon $today): Collection
    {
        return Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->where('status', AppointmentStatus::Confirmed)
            ->whereDate('appointment_date', '>', $today)
            ->with(['patient.user', 'doctor.clinic'])
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->limit(5)
            ->get();
    }
}
