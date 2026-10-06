<?php

namespace App\Support;

use App\Models\Clinic;

/**
 * One eligible clinic, using stored hospital fields only.
 *
 * A missing description stays null. This object does not invent services,
 * symptoms, specialties, or description text.
 */
final readonly class AiClinicContext
{
    public function __construct(
        public int $clinicId,
        public string $clinicName,
        public ?string $clinicDescription,
        public int $departmentId,
        public string $departmentName,
    ) {}

    public static function fromClinic(Clinic $clinic): self
    {
        $department = $clinic->department;

        return new self(
            clinicId: $clinic->id,
            clinicName: $clinic->name,
            clinicDescription: self::storedDescription($clinic->description),
            departmentId: $department->id,
            departmentName: $department->name,
        );
    }

    /**
     * @return array{
     *     clinic_id: int,
     *     clinic_name: string,
     *     clinic_description: string|null,
     *     department_id: int,
     *     department_name: string
     * }
     */
    public function toArray(): array
    {
        return [
            'clinic_id' => $this->clinicId,
            'clinic_name' => $this->clinicName,
            'clinic_description' => $this->clinicDescription,
            'department_id' => $this->departmentId,
            'department_name' => $this->departmentName,
        ];
    }

    private static function storedDescription(?string $description): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        return $description;
    }
}
