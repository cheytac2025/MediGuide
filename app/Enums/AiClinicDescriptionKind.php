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

    /**
     * Exact development / import phrases removed from public-facing clinic cards only.
     * Stored database values and provenance are unchanged.
     *
     * @var list<string>
     */
    private const array PUBLIC_DISPLAY_EXCLUSIONS = [
        'Placeholder clinic for local development. Not hospital data.',
        'Imported from the supplied clinic-schedule poster for this specialization.',
        'Mapping to an official hospital clinic requires confirmation.',
        'Not hospital-verified.',
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

    /**
     * Strip exact development/import phrases for user-facing display.
     * Returns null when nothing displayable remains.
     */
    public static function forPublicDisplay(?string $description): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        $cleaned = $description;

        foreach (self::PUBLIC_DISPLAY_EXCLUSIONS as $phrase) {
            $cleaned = str_ireplace($phrase, ' ', $cleaned);
        }

        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? '';
        $cleaned = trim($cleaned);

        return $cleaned === '' ? null : $cleaned;
    }

    public function mayGroundClinicalReason(): bool
    {
        return $this === self::ApprovedServiceDescription;
    }
}
