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
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Support\Demo\DemoBookingEnvironment;
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

        $user = $request->user();
        $isPatient = $user?->hasRole(RoleName::Patient) === true;

        return response()->json([
            'clinic' => [
                'id' => $clinic->id,
                'name' => $clinic->name,
                'department' => $clinic->department?->name,
            ],
            'doctors' => $doctors,
            'message' => $message,
            'book_appointment_url' => route('patient.book-appointment'),
            'is_authenticated' => $user !== null,
            'is_authenticated_patient' => $isPatient,
            'patient_booking_only_message' => AiBookingIntentController::PATIENT_ACCOUNT_REQUIRED_MESSAGE,
            'auth_required_to_book_message' => AiBookingIntentController::AUTH_REQUIRED_TO_BOOK_MESSAGE,
            'demonstration_account_required_message' => AiBookingIntentController::DEMONSTRATION_ACCOUNT_REQUIRED_MESSAGE,
            'booking_intent_url' => route('ai-front-desk.booking-intent'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function doctorCard(Doctor $doctor, Clinic $clinic): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        $slot = $this->booking->nextAvailableSlot($doctor);
        $hasSlot = $slot !== null;
        $provisional = $hasSlot && (($slot['provisional'] ?? false) === true);
        $nextAvailable = $hasSlot
            ? $this->booking->formatSlotLabel($slot['date'], $slot['time'])
            : null;
        $bookable = $hasSlot;
        if ($provisional && ! DemoBookingEnvironment::mayOfferProvisionalBookingAction($user instanceof User ? $user : null)) {
            $bookable = false;
        }
        $hasActiveSchedule = $doctor->schedules->contains(
            fn (DoctorSchedule $schedule): bool => $schedule->status === DoctorScheduleStatus::Active,
        );

        $availability = $nextAvailable;
        if ($provisional && $nextAvailable !== null) {
            $availability = $bookable
                ? 'Demonstration availability: '.$nextAvailable
                : 'Demonstration availability: '.$nextAvailable.'. '.AiBookingIntentController::DEMONSTRATION_ACCOUNT_REQUIRED_MESSAGE;
        } elseif ($availability === null && ! $hasActiveSchedule) {
            $availability = 'No available schedule';
        }

        return [
            'id' => $doctor->id,
            'display_name' => $doctor->display_name,
            'specialization' => $doctor->specialization,
            'clinic_id' => $clinic->id,
            'clinic' => $clinic->name,
            'bookable' => $bookable,
            'next_available' => $nextAvailable,
            'demonstration_slot' => $provisional,
            'availability' => $availability,
        ];
    }
}
