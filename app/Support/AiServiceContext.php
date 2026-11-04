<?php

namespace App\Support;

/**
 * One hospital-managed specialization available for AI routing.
 *
 * The label is copied from stored doctor.specialization values.
 * Doctor identities are omitted so the model cannot select a person.
 */
final readonly class AiServiceContext
{
    /**
     * @param  list<int>  $clinicIds
     */
    public function __construct(
        public int $serviceId,
        public string $specialization,
        public array $clinicIds,
    ) {}

    public function matchesSpecialization(string $specialization): bool
    {
        return self::normalize($this->specialization) === self::normalize($specialization);
    }

    public static function normalize(string $specialization): string
    {
        return mb_strtolower(trim($specialization));
    }

    /**
     * @return array{service_id: int, specialization: string, clinic_ids: list<int>}
     */
    public function toArray(): array
    {
        return [
            'service_id' => $this->serviceId,
            'specialization' => $this->specialization,
            'clinic_ids' => $this->clinicIds,
        ];
    }
}
