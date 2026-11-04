<?php

namespace App\Services\Ai;

use App\Enums\RoleName;
use App\Models\Doctor;
use App\Services\AiHospitalContextService;
use App\Services\AppointmentBookingService;
use App\Support\AiGuidanceResult;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Illuminate\Http\Request;

/**
 * Builds server-approved Suggested Doctors for a validated AI recommendation.
 *
 * Doctor rows come from the database. Availability uses AppointmentBookingService.
 * This service does not create appointments or trust model-generated doctor IDs.
 */
class AiSuggestedDoctorService
{
    public const int PREVIEW_LIMIT = 3;

    public const string NO_DOCTORS_MESSAGE = 'No doctors are currently available for this service.';

    public const string NO_ONLINE_SLOTS_MESSAGE = 'No online appointments are currently available. Please contact hospital staff for assistance.';

    public function __construct(
        private AiHospitalContextService $hospitalContext,
        private AppointmentBookingService $booking,
    ) {}

    /**
     * @return array{
     *     doctors: list<array<string, mixed>>,
     *     message: ?string,
     *     all_unavailable: bool,
     *     specialization: ?string
     * }
     */
    public function forGuidance(AiGuidanceResult $result): array
    {
        $doctors = collect();

        if (is_string($result->specialization) && $result->specialization !== '') {
            $doctors = $this->hospitalContext->eligibleDoctorsForSpecialization($result->specialization);
        } elseif ($result->clinicId !== null) {
            $doctors = $this->hospitalContext->availableDoctors($result->clinicId)->load('clinic.department');
        }

        $cards = $doctors
            ->map(fn (Doctor $doctor): array => $this->card($doctor))
            ->sort(function (array $left, array $right): int {
                if ($left['bookable'] !== $right['bookable']) {
                    return $right['bookable'] <=> $left['bookable'];
                }

                $leftAt = is_string($left['next_available_sort']) ? $left['next_available_sort'] : '9999-99-99';
                $rightAt = is_string($right['next_available_sort']) ? $right['next_available_sort'] : '9999-99-99';

                return [$leftAt, $left['display_name'], $left['id']]
                    <=> [$rightAt, $right['display_name'], $right['id']];
            })
            ->values()
            ->map(function (array $card): array {
                unset($card['next_available_sort']);

                return $card;
            })
            ->all();

        $message = null;
        $allUnavailable = false;

        if ($cards === []) {
            $message = self::NO_DOCTORS_MESSAGE;
        } elseif (count(array_filter($cards, fn (array $card): bool => $card['bookable'])) === 0) {
            $message = self::NO_ONLINE_SLOTS_MESSAGE;
            $allUnavailable = true;
        }

        return [
            'doctors' => $cards,
            'message' => $message,
            'all_unavailable' => $allUnavailable,
            'specialization' => $result->specialization,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bookingContext(Request $request): array
    {
        return [
            'book_appointment_url' => route('patient.book-appointment'),
            'booking_intent_url' => route('ai-front-desk.booking-intent'),
            'is_authenticated_patient' => $request->user()?->hasRole(RoleName::Patient) === true,
            'preview_limit' => self::PREVIEW_LIMIT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Doctor $doctor): array
    {
        $slot = $this->booking->nextAvailableSlot($doctor);
        $bookable = $slot !== null;
        $availability = $bookable
            ? $this->booking->formatSlotLabel($slot['date'], $slot['time'])
            : null;

        $clinic = $doctor->clinic;
        $specialization = is_string($doctor->specialization) && trim($doctor->specialization) !== ''
            ? trim($doctor->specialization)
            : null;

        return [
            'id' => $doctor->id,
            'display_name' => $doctor->display_name,
            'specialization' => $specialization,
            'clinic_id' => $clinic->id,
            'clinic' => $this->patientFacingClinicName($clinic->name, $specialization),
            'department' => $this->patientFacingDepartment($clinic->department?->name),
            'next_available' => $availability,
            'next_available_sort' => $bookable ? $slot['date'].' '.$slot['time'] : null,
            'bookable' => $bookable,
            'availability' => $availability,
        ];
    }

    private function patientFacingClinicName(string $clinicName, ?string $specialization): ?string
    {
        $clinicName = trim($clinicName);

        if ($clinicName === '') {
            return null;
        }

        if ($this->isAdministrativeLabel($clinicName)) {
            return null;
        }

        if (is_string($specialization) && mb_strtolower($clinicName) === mb_strtolower($specialization)) {
            return null;
        }

        return $clinicName;
    }

    private function patientFacingDepartment(?string $departmentName): ?string
    {
        if ($departmentName === null || trim($departmentName) === '') {
            return null;
        }

        if ($this->isAdministrativeLabel($departmentName)) {
            return null;
        }

        return $departmentName;
    }

    private function isAdministrativeLabel(string $label): bool
    {
        $normalized = mb_strtolower(trim($label));

        return $normalized === mb_strtolower(QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME)
            || $normalized === 'pending hospital mapping'
            || str_contains($normalized, 'pending hospital mapping');
    }
}
