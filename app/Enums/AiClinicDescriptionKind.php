<?php

namespace App\Enums;

/**
 * Distinguishes stored clinic text that may be cited as a service description
 * from administrative mapping notes that must not be treated as clinical
 * capability.
 *
 * This does not invent hospital services. Empty and mapping notes stay
 * non-clinical. Hospital personnel may replace mapping notes with approved
 * service descriptions through clinic management.
 */
enum AiClinicDescriptionKind: string
{
    case None = 'none';
    case AdministrativeMapping = 'administrative_mapping';
    case ApprovedServiceDescription = 'approved_service_description';

    /**
     * @var list<string>
     */
    private const array ADMINISTRATIVE_MARKERS = [
        'imported from the supplied clinic-schedule poster',
        'mapping to an official hospital clinic',
        'not hospital-verified',
        'placeholder clinic for local development',
        'not hospital data.',
    ];

    public static function fromStored(?string $description): self
    {
        if ($description === null || trim($description) === '') {
            return self::None;
        }

        $normalized = mb_strtolower(trim($description));

        foreach (self::ADMINISTRATIVE_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return self::AdministrativeMapping;
            }
        }

        return self::ApprovedServiceDescription;
    }

    public function mayGroundClinicalReason(): bool
    {
        return $this === self::ApprovedServiceDescription;
    }
}
