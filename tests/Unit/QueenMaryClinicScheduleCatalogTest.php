<?php

namespace Tests\Unit;

use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Tests\TestCase;

class QueenMaryClinicScheduleCatalogTest extends TestCase
{
    public function test_catalog_contains_sixteen_source_doctors_and_no_phone_numbers(): void
    {
        $doctors = QueenMaryClinicScheduleCatalog::doctors();
        $encoded = json_encode($doctors, JSON_THROW_ON_ERROR);

        $this->assertCount(16, $doctors);
        $this->assertCount(16, array_unique(array_column($doctors, 'key')));
        $this->assertCount(16, array_unique(array_column($doctors, 'display_name')));
        $this->assertDoesNotMatchRegularExpression('/09\d{2}/', $encoded);
        $this->assertStringNotContainsString('Ivy', $encoded);
        $this->assertStringNotContainsString('invented', $encoded);
    }

    public function test_internal_medicine_is_not_merged_with_im_subspecialties(): void
    {
        $specializations = array_column(QueenMaryClinicScheduleCatalog::doctors(), 'specialization');

        $this->assertContains('Internal Medicine', $specializations);
        $this->assertContains('IM-Cardiologist', $specializations);
        $this->assertContains('IM-Nephrologist', $specializations);
        $this->assertContains('IM-Oncologist', $specializations);
        $this->assertContains('Pediatrician', $specializations);
        $this->assertContains('Developmental and Behavioral Pediatrician', $specializations);
        $this->assertContains('Pediatric Rheumatologist', $specializations);
    }

    public function test_appointment_only_doctors_have_no_bookable_windows(): void
    {
        foreach (QueenMaryClinicScheduleCatalog::doctors() as $doctor) {
            if (! in_array($doctor['booking_mode'], ['by_appointment', 'strictly_by_appointment'], true)) {
                continue;
            }

            $this->assertSame([], $doctor['schedules']);
        }
    }

    public function test_pending_department_is_explicitly_inferred(): void
    {
        $this->assertSame('Pending hospital mapping', QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME);
        $this->assertStringContainsString('not an official Queen Mary department', QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_DESCRIPTION);
    }
}
