<?php

namespace App\Services\Ai;

use App\Enums\AiClinicDescriptionKind;
use App\Enums\AiSafetyKind;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\HospitalDataSource;
use App\Models\Clinic;
use App\Services\AiHospitalContextService;
use App\Support\AiGuidanceResult;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Illuminate\Support\Collection;

/**
 * Turns an already validated hospital record into visitor navigation.
 *
 * This class does not classify urgency and does not match symptoms to clinics.
 * A safety response stays on staff assistance until the hospital verifies a
 * destination. A listed specialization is not described as a physical place.
 */
class HospitalNavigationResolver
{
    public const string STATUS_LISTED_SERVICE = 'listed_service';

    public const string STATUS_VERIFIED_DESTINATION = 'verified_destination';

    public const string STATUS_STAFF_ASSISTANCE = 'staff_assistance';

    public const string WHERE_TO_GO_ENGLISH = 'Where to go';

    public const string WHERE_TO_GO_FILIPINO = 'Saan po kayo maaaring pumunta?';

    public const string PROMPT_GUIDANCE_ENGLISH = 'Please ask hospital staff where to go for prompt in-person assessment. MediGuide does not have a verified urgent-assessment location on file. A routine online appointment is not a substitute for that assessment.';

    public const string PROMPT_GUIDANCE_FILIPINO = 'Magtanong po sa staff ng ospital kung saan kayo dapat pumunta para sa agarang personal na pagsusuri. Wala pang nabeberipikang lugar para sa agarang pagsusuri sa talaan ng ospital. Hindi sapat ang karaniwang online appointment para dito.';

    public const string EMERGENCY_GUIDANCE_ENGLISH = 'Seek immediate emergency medical assistance now. MediGuide does not have a verified emergency destination on file. Do not wait for a routine appointment.';

    public const string EMERGENCY_GUIDANCE_FILIPINO = 'Humingi po agad ng emergency na tulong medikal ngayon. Wala pang nabeberipikang emergency na destinasyon sa talaan ng ospital. Huwag po hintayin ang karaniwang appointment.';

    public const string UNCERTAIN_HEADING_ENGLISH = 'How to get help';

    public const string UNCERTAIN_HEADING_FILIPINO = 'Paano po kayo matutulungan';

    public const string UNCERTAIN_GUIDANCE_ENGLISH = 'The available information is not enough to choose a clinic. Please ask hospital staff for help. MediGuide will not guess a clinic or a location.';

    public const string UNCERTAIN_GUIDANCE_FILIPINO = 'Hindi pa sapat ang impormasyon para pumili ng klinika. Magtanong po sa staff ng ospital. Hindi hulaan ng MediGuide ang klinika o ang lugar.';

    public const string LISTED_NOTE_ENGLISH = 'This is a navigation suggestion, not a diagnosis. It is a listed hospital record, not a verified physical clinic location.';

    public const string LISTED_NOTE_FILIPINO = 'Ito ay mungkahi sa pag-navigate, hindi diagnosis. Ito ay nakalistang talaan ng ospital, hindi isang nabeberipikang pisikal na lokasyon ng klinika.';

    public const string VERIFIED_NOTE_ENGLISH = 'This is a navigation suggestion, not a diagnosis. This service name is on the hospital verified record. Online booking appears only when an appointment slot is actually available.';

    public const string VERIFIED_NOTE_FILIPINO = 'Ito ay mungkahi sa pag-navigate, hindi diagnosis. Ang pangalan ng serbisyong ito ay nasa nabeberipikang talaan ng ospital. Ang online booking ay lumalabas lamang kapag may aktwal na appointment slot.';

    public function __construct(private AiHospitalContextService $hospital) {}

    /**
     * @return array<string, mixed>
     */
    public function forRecommendation(AiGuidanceResult $result, bool $filipino): array
    {
        $specialization = is_string($result->specialization) ? trim($result->specialization) : '';
        $doctors = $specialization === ''
            ? collect()
            : $this->hospital->eligibleDoctorsForSpecialization($specialization);

        $clinics = $doctors
            ->map(fn ($doctor): ?Clinic => $doctor->clinic)
            ->filter(fn ($clinic): bool => $clinic instanceof Clinic)
            ->unique(fn (Clinic $clinic): int => $clinic->id)
            ->values();

        if ($clinics->isEmpty() && $result->clinicId !== null) {
            $clinic = Clinic::query()->with('department')->find($result->clinicId);

            if ($clinic instanceof Clinic && $this->isEligibleRecord($clinic)) {
                $clinics = collect([$clinic]);
                $specialization = $specialization !== '' ? $specialization : $this->publicLabel($clinic, $clinic->name);
            }
        }

        if ($clinics->isEmpty() || $specialization === '') {
            return $this->staffAssistance('uncertain', $filipino);
        }

        return $this->fromClinics($clinics, $specialization, $filipino);
    }

    /**
     * Safety navigation never selects a clinic from the catalog.
     *
     * @return array<string, mixed>
     */
    public function forSafety(AiSafetyKind $kind, bool $filipino): array
    {
        return $this->staffAssistance(
            $kind === AiSafetyKind::Emergency ? 'emergency' : 'prompt',
            $filipino,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forUncertain(bool $filipino): array
    {
        return $this->staffAssistance('uncertain', $filipino);
    }

    /**
     * @param  Collection<int, Clinic>  $clinics
     * @return array<string, mixed>
     */
    private function fromClinics(Collection $clinics, string $specialization, bool $filipino): array
    {
        $verified = $clinics
            ->filter(fn (Clinic $clinic): bool => $this->isVerifiedDestination($clinic, $specialization))
            ->values();
        $label = $this->publicLabel($clinics->first(), $specialization);

        if ($verified->count() === 1 && $clinics->count() === 1) {
            $destination = $verified->first();

            return $this->payload(
                status: self::STATUS_VERIFIED_DESTINATION,
                heading: null,
                guidance: null,
                note: $filipino ? self::VERIFIED_NOTE_FILIPINO : self::VERIFIED_NOTE_ENGLISH,
                serviceLabel: $this->publicLabel($destination, $specialization),
                bookingPermitted: true,
                temporaryMapping: false,
            );
        }

        return $this->payload(
            status: self::STATUS_LISTED_SERVICE,
            heading: null,
            guidance: null,
            note: $filipino ? self::LISTED_NOTE_FILIPINO : self::LISTED_NOTE_ENGLISH,
            serviceLabel: $label,
            bookingPermitted: true,
            temporaryMapping: $clinics->contains(
                fn (Clinic $clinic): bool => ! $this->isVerifiedDestination($clinic, $specialization),
            ),
        );
    }

    private function isEligibleRecord(Clinic $clinic): bool
    {
        $department = $clinic->department;

        return $clinic->status === ClinicStatus::Active
            && $department->status === DepartmentStatus::Active
            && $clinic->isEligibleForAiGrounding()
            && $department->isEligibleForAiGrounding();
    }

    private function isVerifiedDestination(Clinic $clinic, string $specialization): bool
    {
        if (! $this->isEligibleRecord($clinic)) {
            return false;
        }

        $department = $clinic->department;

        if ($clinic->data_source !== HospitalDataSource::Verified || $department->data_source !== HospitalDataSource::Verified) {
            return false;
        }

        if ($this->isPendingLabel($clinic->name) || $this->isPendingLabel($department->name)) {
            return false;
        }

        if (AiClinicDescriptionKind::fromStored($clinic->description) === AiClinicDescriptionKind::AdministrativeMapping) {
            return false;
        }

        return mb_strtolower(trim($clinic->name)) !== mb_strtolower(trim($specialization));
    }

    private function publicLabel(Clinic $clinic, string $specialization): string
    {
        if ($this->isPendingLabel($clinic->name) || $this->isPendingLabel($clinic->department->name)) {
            return $specialization;
        }

        $name = trim($clinic->name);

        return $name !== '' ? $name : $specialization;
    }

    private function isPendingLabel(string $label): bool
    {
        $normalized = mb_strtolower(trim($label));

        return $normalized === mb_strtolower(QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME)
            || str_contains($normalized, 'pending hospital mapping');
    }

    /**
     * @return array<string, mixed>
     */
    private function staffAssistance(string $kind, bool $filipino): array
    {
        [$heading, $guidance] = match ($kind) {
            'emergency' => $filipino
                ? [self::WHERE_TO_GO_FILIPINO, self::EMERGENCY_GUIDANCE_FILIPINO]
                : [self::WHERE_TO_GO_ENGLISH, self::EMERGENCY_GUIDANCE_ENGLISH],
            'prompt' => $filipino
                ? [self::WHERE_TO_GO_FILIPINO, self::PROMPT_GUIDANCE_FILIPINO]
                : [self::WHERE_TO_GO_ENGLISH, self::PROMPT_GUIDANCE_ENGLISH],
            default => $filipino
                ? [self::UNCERTAIN_HEADING_FILIPINO, self::UNCERTAIN_GUIDANCE_FILIPINO]
                : [self::UNCERTAIN_HEADING_ENGLISH, self::UNCERTAIN_GUIDANCE_ENGLISH],
        };

        return $this->payload(
            status: self::STATUS_STAFF_ASSISTANCE,
            heading: $heading,
            guidance: $guidance,
            note: null,
            serviceLabel: null,
            bookingPermitted: false,
            temporaryMapping: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $status,
        ?string $heading,
        ?string $guidance,
        ?string $note,
        ?string $serviceLabel,
        bool $bookingPermitted,
        bool $temporaryMapping,
    ): array {
        return [
            'status' => $status,
            'heading' => $heading,
            'guidance' => $guidance,
            'note' => $note,
            'service_label' => $serviceLabel,
            'booking_permitted' => $bookingPermitted,
            'temporary_mapping' => $temporaryMapping,
        ];
    }
}
