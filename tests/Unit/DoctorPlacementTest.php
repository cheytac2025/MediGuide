<?php

namespace Tests\Unit;

use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Support\DoctorPlacement;
use PHPUnit\Framework\TestCase;

class DoctorPlacementTest extends TestCase
{
    public function test_a_clinic_name_that_repeats_the_specialization_is_omitted(): void
    {
        $labels = DoctorPlacement::labels($this->doctor(
            'Internal Medicine',
            'Internal Medicine',
            'Pending hospital mapping',
        ));

        $this->assertSame('Internal Medicine', $labels['specialty']);
        $this->assertSame('Pending hospital mapping', $labels['detail']);
        $this->assertSame('Internal Medicine · Pending hospital mapping', $labels['subtitle']);
        $this->assertStringNotContainsString('Internal Medicine · Internal Medicine', $labels['subtitle']);
    }

    public function test_a_distinct_clinic_name_stays_visible(): void
    {
        $labels = DoctorPlacement::labels($this->doctor(
            'Cardiology',
            'Heart Clinic',
            'Medical Services',
        ));

        $this->assertSame('Cardiology', $labels['specialty']);
        $this->assertSame('Heart Clinic · Medical Services', $labels['detail']);
        $this->assertSame('Cardiology · Heart Clinic · Medical Services', $labels['subtitle']);
    }

    public function test_missing_names_keep_their_placeholders(): void
    {
        $doctor = new Doctor;
        $doctor->setRelation('clinic', null);
        $labels = DoctorPlacement::labels($doctor);

        $this->assertSame('No specialization listed', $labels['specialty']);
        $this->assertSame('Unknown clinic · Unknown department', $labels['detail']);
    }

    private function doctor(string $specialization, string $clinic, string $department): Doctor
    {
        $doctor = new Doctor(['specialization' => $specialization]);
        $clinicModel = new Clinic(['name' => $clinic]);
        $clinicModel->setRelation('department', new Department(['name' => $department]));
        $doctor->setRelation('clinic', $clinicModel);

        return $doctor;
    }
}
