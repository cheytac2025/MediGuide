<?php

namespace App\Support;

use App\Models\Doctor;

/**
 * Presentation labels for a doctor's specialty, clinic, and department.
 * A clinic name that repeats the specialization is omitted.
 */
final class DoctorPlacement
{
    /**
     * @return array{specialty: string, detail: string, subtitle: string}
     */
    public static function labels(Doctor $doctor): array
    {
        $specialization = trim((string) $doctor->specialization);
        $clinic = trim((string) ($doctor->clinic?->name ?? ''));
        $department = trim((string) ($doctor->clinic?->department?->name ?? ''));
        $specialty = $specialization !== '' ? $specialization : 'No specialization listed';
        $clinicLabel = $clinic !== '' ? $clinic : 'Unknown clinic';
        $departmentLabel = $department !== '' ? $department : 'Unknown department';
        $clinicRepeatsSpecialty = $specialization !== '' && strcasecmp($specialization, $clinic) === 0;
        $detail = $clinicRepeatsSpecialty
            ? $departmentLabel
            : $clinicLabel.' · '.$departmentLabel;

        return [
            'specialty' => $specialty,
            'detail' => $detail,
            'subtitle' => $specialty.' · '.$detail,
        ];
    }
}
