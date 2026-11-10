<?php

namespace App\Http\Controllers\Patient;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Services\AppointmentBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiClinicDoctorsController extends Controller
{
    public function __construct(private AppointmentBookingService $booking) {}

    /**
     * List active doctors for an active clinic (public after AI disclaimer).
     */
    public function __invoke(Request $request, Clinic $clinic): JsonResponse
    {
        $clinic->loadMissing('department');

        if (
            $clinic->status !== ClinicStatus::Active
            || $clinic->department?->status !== DepartmentStatus::Active
            || ! $clinic->isEligibleForAiGrounding()
        ) {
            return response()->json([
                'clinic' => null,
                'doctors' => [],
                'message' => 'This clinic is not available at the moment.',
            ], 404);
        }

        $doctors = $clinic->doctors()
            ->where('status', DoctorStatus::Active)
            ->forAiGrounding()
            ->with(['schedules' => fn ($query) => $query->where('status', DoctorScheduleStatus::Active)])
            ->orderBy('display_name')
            ->get()
            ->map(fn (Doctor $doctor): array => $this->doctorCard($doctor, $clinic))
            ->values();

        $message = null;

        if ($doctors->isEmpty()) {
            $message = 'No available doctors for this clinic at the moment.';
        } elseif ($doctors->every(fn (array $doctor): bool => ! $doctor['bookable'])) {
            $message = 'Doctors are listed, but none currently have an available schedule.';
        }

        return response()->json([
            'clinic' => [
                'id' => $clinic->id,
                'name' => $clinic->name,
                'department' => $clinic->department?->name,
            ],
            'doctors' => $doctors,
            'message' => $message,
            'book_appointment_url' => route('patient.book-appointment'),
            'is_authenticated_patient' => $request->user()?->hasRole(RoleName::Patient) === true,
            'booking_intent_url' => route('ai-front-desk.booking-intent'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function doctorCard(Doctor $doctor, Clinic $clinic): array
    {
        $slot = $this->booking->nextAvailableSlot($doctor);
        $bookable = $slot !== null;
        $nextAvailable = $bookable
            ? $this->booking->formatSlotLabel($slot['date'], $slot['time'])
            : null;
        $hasActiveSchedule = $doctor->schedules->contains(
            fn (DoctorSchedule $schedule): bool => $schedule->status === DoctorScheduleStatus::Active,
        );

        return [
            'id' => $doctor->id,
            'display_name' => $doctor->display_name,
            'specialization' => $doctor->specialization,
            'clinic_id' => $clinic->id,
            'clinic' => $clinic->name,
            'bookable' => $bookable,
            'next_available' => $nextAvailable,
            'availability' => $nextAvailable ?? ($hasActiveSchedule ? null : 'No available schedule'),
        ];
    }
}
