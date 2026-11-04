<?php

namespace App\Support;

use App\Enums\HospitalDataSource;
use InvalidArgumentException;

/**
 * Hospital services supplied to a guidance model for one request.
 *
 * Clinic membership and service membership are allow-lists for that request.
 * A later clinic_id or service_id must still be checked against this snapshot
 * and against current records.
 */
final readonly class AiHospitalContext
{
    /**
     * @var list<AiClinicContext>
     */
    public array $clinics;

    /**
     * @var list<AiServiceContext>
     */
    public array $services;

    /**
     * @param  array<int, mixed>  $clinics
     * @param  array<int, mixed>  $services
     */
    public function __construct(
        public HospitalDataSource $dataSource,
        array $clinics,
        array $services = [],
    ) {
        $normalizedClinics = [];

        foreach ($clinics as $clinic) {
            if (! $clinic instanceof AiClinicContext) {
                throw new InvalidArgumentException('Hospital context clinics must be clinic context records.');
            }

            $normalizedClinics[] = $clinic;
        }

        $normalizedServices = [];

        foreach ($services as $service) {
            if (! $service instanceof AiServiceContext) {
                throw new InvalidArgumentException('Hospital context services must be service context records.');
            }

            $normalizedServices[] = $service;
        }

        $this->clinics = $normalizedClinics;
        $this->services = $normalizedServices;
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

    public function includesService(int $serviceId): bool
    {
        return $this->serviceById($serviceId) instanceof AiServiceContext;
    }

    public function serviceById(int $serviceId): ?AiServiceContext
    {
        foreach ($this->services as $service) {
            if ($service->serviceId === $serviceId) {
                return $service;
            }
        }

        return null;
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
     *         description_kind: string,
     *         department_id: int,
     *         department_name: string
     *     }>,
     *     services: list<array{service_id: int, specialization: string, clinic_ids: list<int>}>
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
            'services' => array_map(
                fn (AiServiceContext $service): array => $service->toArray(),
                $this->services,
            ),
        ];
    }
}
