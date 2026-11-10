<?php

namespace Tests\Unit;

use App\Support\AiUrgentConcernDetector;
use App\Support\AiVisitorReply;
use Tests\TestCase;

class AiUrgentConcernDetectorTest extends TestCase
{
    public function test_ordinary_complaints_are_not_treated_as_emergencies(): void
    {
        $detector = new AiUrgentConcernDetector;

        $this->assertFalse($detector->isUrgent('Masakit ulo ko.'));
        $this->assertFalse($detector->isUrgent('Masaket ulo ko'));
        $this->assertFalse($detector->isUrgent('Masaket ulo'));
        $this->assertFalse($detector->isUrgent('Nahihilo ako'));
        $this->assertFalse($detector->isUrgent('My stomach hurts'));
        $this->assertFalse($detector->isUrgent('Sumasakit dibdib ko'));
        $this->assertFalse($detector->isUrgent('I feel dizzy'));
        $this->assertFalse($detector->isUrgent('I have a fever'));
        $this->assertFalse($detector->isUrgent('Sobrang sakit ng ulo bigla-bigla.'));
        $this->assertFalse($detector->isUrgent('Masakit ulo ko bigla-bigla.'));
        $this->assertFalse($detector->isUrgent('Masakit ang tiyan ko sa kaliwa sa baba. Sumakit na lang siya bilga.'));
        $this->assertFalse($detector->isUrgent('Medyo masakit ang tiyan ko. Bigla lang.'));
    }

    public function test_high_signal_warning_signs_are_flagged(): void
    {
        $detector = new AiUrgentConcernDetector;

        $this->assertTrue($detector->isUrgent('I have a sudden severe headache and I cannot speak.'));
        $this->assertTrue($detector->isUrgent('I have chest pain and I cannot breathe.'));
        $this->assertTrue($detector->isUrgent('Hindi ako makahinga'));
        $this->assertTrue($detector->isUrgent('Nawalan ng malay kanina'));
        $this->assertTrue($detector->isUrgent('Severe uncontrolled bleeding'));
        $this->assertTrue($detector->isUrgent('Biglang sobrang sakit ng ulo'));
        $this->assertTrue($detector->isUrgent('Biglaang sobrang sakit ng ulo'));
        $this->assertTrue($detector->isUrgent('Sobrang sakit ng ulo bigla'));
        $this->assertTrue($detector->isUrgent('Parang mahihimatay ako'));
        $this->assertTrue($detector->isUrgent('I feel like I am about to collapse.'));
        $this->assertTrue($detector->isUrgent('Parang stroke po'));
        $this->assertTrue($detector->isUrgent('Atake sa puso'));
        $this->assertTrue($detector->isUrgent('Hindi ko maigalaw ang kaliwang kamay.'));
        $this->assertTrue($detector->isUrgent('May panghihina ako sa kaliwang kamay.'));
        $this->assertTrue($detector->isUrgent('I have weakness on one side.'));
        $this->assertTrue($detector->isUrgent('Bigla akong hindi makakita.'));
        $this->assertTrue($detector->isUrgent('I have sudden vision loss.'));
        $this->assertTrue($detector->isUrgent('Hindi magising ang bata.'));
        $this->assertTrue($detector->isUrgent('Pakiramdam ko parang mag-collapse ako.'));
        $this->assertTrue($detector->isUrgent('Biglang sumakit nang sobrang tindi ang ulo ko.'));
        $this->assertTrue($detector->isUrgent('Bigla akong nawalan ng paningin sa isang mata.'));
        $this->assertFalse($detector->isUrgent('The bright light hurts my eyes.'));
    }

    public function test_negated_warning_signs_are_not_treated_as_reported(): void
    {
        $detector = new AiUrgentConcernDetector;

        $this->assertFalse($detector->isUrgent('Wala akong panghihina.'));
        $this->assertFalse($detector->isUrgent('Hindi ako may panghihina.'));
        $this->assertFalse($detector->isUrgent('Masakit ulo ko. Wala akong panghihina.'));
        $this->assertFalse($detector->isUrgent('Hindi ako atake sa puso.'));
        $this->assertTrue($detector->attributesUnreportedSymptoms(
            'Dahil mayroon kang nararamdamang panghihina kasabay ng matinding sakit ng ulo.',
            'Masaket ulo ko',
        ));
        $this->assertFalse($detector->attributesUnreportedSymptoms(
            'If you reported one sided weakness, seek emergency help now.',
            'I have weakness on one side.',
        ));
    }

    public function test_declined_and_unknown_replies_are_recognized(): void
    {
        $this->assertTrue(AiVisitorReply::declinedOrUnknown('Hindi ko alam.'));
        $this->assertTrue(AiVisitorReply::declinedOrUnknown("I don't know"));
        $this->assertTrue(AiVisitorReply::declinedOrUnknown('Ayaw ko sagutin.'));
        $this->assertTrue(AiVisitorReply::declinedOrUnknown('prefer not to say'));
        $this->assertFalse(AiVisitorReply::declinedOrUnknown('Dalawang araw na.'));
        $this->assertFalse(AiVisitorReply::declinedOrUnknown('Masakit ulo ko.'));
        $this->assertFalse(AiVisitorReply::declinedOrUnknown("I'm not sure which specialist I need"));
        $this->assertTrue(AiVisitorReply::declinedOrUnknown("I'm not sure."));
        $this->assertTrue(AiVisitorReply::requestsSpecialistNavigation('I do not know which doctor I should see'));
        $this->assertFalse(AiVisitorReply::statesHealthConcern("I'm not sure which specialist I need"));
        $this->assertFalse(AiVisitorReply::statesHealthConcern('I do not know which doctor I should see'));
        $this->assertFalse(AiVisitorReply::statesHealthConcern('Hindi ko alam kung anong specialist ang kailangan ko.'));
        $this->assertTrue(AiVisitorReply::statesHealthConcern('I have a headache'));
        $this->assertTrue(AiVisitorReply::statesHealthConcern('Parang may kumikirot sa tagiliran ko. Which specialist should I see?'));
        $this->assertTrue(AiVisitorReply::statesHealthConcern('nagsusuka ako which doctor'));
        $this->assertTrue(AiVisitorReply::statesHealthConcern('masakit ulo ko'));
        $this->assertTrue(AiVisitorReply::negativeConfirmation('Hindi'));
        $this->assertTrue(AiVisitorReply::negativeConfirmation('No'));
        $this->assertFalse(AiVisitorReply::negativeConfirmation('Dalawang araw na.'));
    }

    public function test_equivalent_questions_are_detected_and_multiple_questions_are_reduced(): void
    {
        $this->assertSame('bigla', AiVisitorReply::applyInformalSpelling('bilga'));
        $this->assertSame('biglaan', AiVisitorReply::applyInformalSpelling('bilgaan'));
        $this->assertSame('sumakit na lang siya bigla', AiVisitorReply::normalize('sumakit na lang siya bilga.'));
        $this->assertTrue(AiVisitorReply::questionsAreEquivalent(
            'Gaano na katagal sumasakit ang ulo mo?',
            'Gaano na katagal?',
        ));
        $this->assertTrue(AiVisitorReply::questionsAreEquivalent(
            'Gaano na po katagal ito?',
            'How long has this been happening?',
        ));
        $this->assertFalse(AiVisitorReply::questionsAreEquivalent(
            'Gaano na katagal sumasakit ang ulo mo?',
            'May lagnat ka ba?',
        ));
        $this->assertSame('rin', AiVisitorReply::applyInformalSpelling('ren'));
        $this->assertSame('wala naman', AiVisitorReply::withoutLanguageInstruction('wala naman. magtagalog ka'));
        $this->assertSame('filipino', AiVisitorReply::explicitLanguagePreference('magtagalog ka'));
        $this->assertSame('english', AiVisitorReply::explicitLanguagePreference('Please speak English'));
        $this->assertSame(
            'Gaano na katagal sumasakit ang ulo mo?',
            AiVisitorReply::firstQuestion('Gaano na katagal sumasakit ang ulo mo? May iba ka bang nararamdaman? May lagnat ka ba?'),
        );
    }
}
