<?php

namespace App\Support;

use App\Enums\AiClinicDescriptionKind;
use App\Models\Clinic;

/**
 * One eligible clinic, using stored hospital fields only.
 *
 * A missing description stays null. Administrative mapping notes are copied
 * as stored text and marked so a model cannot treat them as clinical
 * capability. This object does not invent services, symptoms, specialties,
 * or description text.
 */
final readonly class AiClinicContext
{
    public function __construct(
        public int $clinicId,
        public string $clinicName,
        public ?string $clinicDescription,
        public AiClinicDescriptionKind $descriptionKind,
        public int $departmentId,
        public string $departmentName,
    ) {}

    public static function fromClinic(Clinic $clinic): self
    {
        $department = $clinic->department;
        $description = self::storedDescription($clinic->description);

        return new self(
            clinicId: $clinic->id,
            clinicName: $clinic->name,
            clinicDescription: $description,
            descriptionKind: AiClinicDescriptionKind::fromStored($description),
            departmentId: $department->id,
            departmentName: $department->name,
        );
    }

    /**
     * @return array{
     *     clinic_id: int,
     *     clinic_name: string,
     *     clinic_description: string|null,
     *     description_kind: string,
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
            'description_kind' => $this->descriptionKind->value,
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
