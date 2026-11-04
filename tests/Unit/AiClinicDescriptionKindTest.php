<?php

namespace Tests\Unit;

use App\Enums\AiClinicDescriptionKind;
use App\Support\AiVisitorReply;
use Tests\TestCase;

class AiClinicDescriptionKindTest extends TestCase
{
    public function test_empty_descriptions_are_none(): void
    {
        $this->assertSame(AiClinicDescriptionKind::None, AiClinicDescriptionKind::fromStored(null));
        $this->assertSame(AiClinicDescriptionKind::None, AiClinicDescriptionKind::fromStored(''));
        $this->assertSame(AiClinicDescriptionKind::None, AiClinicDescriptionKind::fromStored('   '));
        $this->assertFalse(AiClinicDescriptionKind::None->mayGroundClinicalReason());
    }

    public function test_import_mapping_notes_are_administrative(): void
    {
        $kind = AiClinicDescriptionKind::fromStored(
            'Imported from the supplied clinic-schedule poster for this specialization. Mapping to an official hospital clinic requires confirmation. Not hospital-verified.',
        );

        $this->assertSame(AiClinicDescriptionKind::AdministrativeMapping, $kind);
        $this->assertFalse($kind->mayGroundClinicalReason());
    }

    public function test_development_placeholder_notes_are_administrative(): void
    {
        $kind = AiClinicDescriptionKind::fromStored('Placeholder clinic for local development. Not hospital data.');

        $this->assertSame(AiClinicDescriptionKind::AdministrativeMapping, $kind);
        $this->assertFalse($kind->mayGroundClinicalReason());
    }

    public function test_hospital_maintained_text_is_an_approved_service_description(): void
    {
        $kind = AiClinicDescriptionKind::fromStored('Outpatient consultation for adult internal medicine concerns.');

        $this->assertSame(AiClinicDescriptionKind::ApprovedServiceDescription, $kind);
        $this->assertTrue($kind->mayGroundClinicalReason());
    }

    public function test_classifier_does_not_invent_clinical_capabilities(): void
    {
        $kind = AiClinicDescriptionKind::fromStored(
            'Imported from the supplied clinic-schedule poster for this specialization. Mapping to an official hospital clinic requires confirmation. Not hospital-verified.',
        );

        $this->assertSame('administrative_mapping', $kind->value);
        $this->assertNotSame('approved_service_description', $kind->value);
    }

    public function test_generic_follow_up_questions_are_detected(): void
    {
        $this->assertTrue(AiVisitorReply::isGenericFollowUp('Pwede po bang dagdagan ang detalye ng nararamdaman ninyo?'));
        $this->assertTrue(AiVisitorReply::isGenericFollowUp('Can you tell me a bit more about what you are feeling?'));
        $this->assertTrue(AiVisitorReply::isGenericFollowUp('Can you provide more details?'));
        $this->assertFalse(AiVisitorReply::isGenericFollowUp('Kailan po nagsimula ang sakit ng ulo?'));
        $this->assertFalse(AiVisitorReply::isGenericFollowUp('Gaano na po katagal sumasakit ang ulo ninyo?'));
    }
}
