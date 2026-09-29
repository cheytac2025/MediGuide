<?php

namespace App\Http\Controllers\Doctor;

use App\Enums\DayOfWeek;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Doctor\Concerns\InteractsWithLinkedDoctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Support\DoctorHeader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorScheduleController extends Controller
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

        $schedules = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->get()
            ->sortBy([
                fn (DoctorSchedule $schedule) => $this->daySortOrder($schedule->day_of_week),
                fn (DoctorSchedule $schedule) => $schedule->start_time,
            ])
            ->values();

        return view('doctor.schedule', [
            'doctor' => DoctorHeader::from($user, $doctor),
            'active' => 'schedule',
            'schedules' => $schedules,
        ]);
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
}
