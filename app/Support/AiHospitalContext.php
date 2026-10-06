<?php

namespace App\Support;

use App\Enums\HospitalDataSource;
use InvalidArgumentException;

/**
 * Hospital services supplied to a future guidance model for one request.
 *
 * Clinic membership is the allow-list for that request. A later clinic_id
 * must still be checked against this snapshot and against current records.
 */
final readonly class AiHospitalContext
{
    /**
     * @var list<AiClinicContext>
     */
    public array $clinics;

    /**
     * @param  array<int, mixed>  $clinics
     */
    public function __construct(
        public HospitalDataSource $dataSource,
        array $clinics,
    ) {
        $normalized = [];

        foreach ($clinics as $clinic) {
            if (! $clinic instanceof AiClinicContext) {
                throw new InvalidArgumentException('Hospital context clinics must be clinic context records.');
            }

            $normalized[] = $clinic;
        }

        $this->clinics = $normalized;
    }

    public function includesClinic(int $clinicId): bool
    {
        foreach ($this->clinics as $clinic) {
            if ($clinic->clinicId === $clinicId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    public function allowedClinicIds(): array
    {
        return array_map(
            fn (AiClinicContext $clinic): int => $clinic->clinicId,
            $this->clinics,
        );
    }

    /**
     * @return array{
     *     data_source: string,
     *     clinics: list<array{
     *         clinic_id: int,
     *         clinic_name: string,
     *         clinic_description: string|null,
     *         department_id: int,
     *         department_name: string
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'data_source' => $this->dataSource->value,
            'clinics' => array_map(
                fn (AiClinicContext $clinic): array => $clinic->toArray(),
                $this->clinics,
            ),
        ];
    }
}
