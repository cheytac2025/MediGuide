<?php

namespace App\Support;

/**
 * Conservative Laravel overlay for emergency warning signs and a limited
 * set of concerning presentations that may warrant prompt in-person
 * evaluation.
 *
 * This is not a triage protocol, diagnosis, disease dictionary, or
 * numerical clinical score. It inspects visitor-reported text only.
 * Assistant questions and system-prompt examples are not scanned unless
 * the visitor clearly affirmed the latest question. Unknown answers are
 * not treated as denials. Denial of fever or vomiting does not cancel a
 * recognized concerning pattern. Qualified hospital personnel must review
 * these heuristics before real-world deployment.
 */
final class AiNavigationSafety
{
    public function __construct(
        private AiUrgentConcernDetector $urgentConcerns,
    ) {}

    public function classify(string $patientText, AiConversationState $state, string $latestConcern): AiSafetyDecision
    {
        $normalized = $this->urgentConcerns->normalize(
            $this->patientEvidence($patientText, $state, $latestConcern),
        );

        if ($normalized === '') {
            return AiSafetyDecision::none();
        }

        if ($this->urgentConcerns->isUrgent($normalized) || $this->urgentConcerns->isUrgent($patientText)) {
            return AiSafetyDecision::emergency();
        }

        if (! config('ai.prompt_evaluation.enabled', true)) {
            return AiSafetyDecision::none();
        }

        if ($this->rightLowerAbdominalPain($normalized)) {
            return AiSafetyDecision::promptEvaluation('right_lower_abdominal_pain');
        }

        if ($this->severeOrWorseningAbdominalPain($normalized)) {
            return AiSafetyDecision::promptEvaluation('severe_or_worsening_abdominal_pain');
        }

        if ($this->seriousInjury($normalized)) {
            return AiSafetyDecision::promptEvaluation('serious_injury');
        }

        if ($this->pregnancyWarning($normalized)) {
            return AiSafetyDecision::promptEvaluation('pregnancy_warning_sign');
        }

        if ($this->concerningChildPresentation($normalized)) {
            return AiSafetyDecision::promptEvaluation('concerning_child_presentation');
        }

        if (config('ai.prompt_evaluation.extended') && $this->isolatedChestPain($normalized)) {
            return AiSafetyDecision::promptEvaluation('isolated_chest_pain_extended');
        }

        return AiSafetyDecision::none();
    }

    private function patientEvidence(string $patientText, AiConversationState $state, string $latestConcern): string
    {
        $evidence = trim($patientText);

        if (! AiVisitorReply::positiveConfirmation($latestConcern)) {
            return $evidence;
        }

        $question = $this->latestAssistantQuestion($state);

        if ($question === null) {
            return $evidence;
        }

        return trim($evidence."\n".$question);
    }

    private function latestAssistantQuestion(AiConversationState $state): ?string
    {
        if ($state->askedQuestions === []) {
            return null;
        }

        return $state->askedQuestions[array_key_last($state->askedQuestions)];
    }

    private function rightLowerAbdominalPain(string $normalized): bool
    {
        if (! $this->mentionsAbdomen($normalized)) {
            return false;
        }

        if ($this->containsAffirmed($normalized, 'rlq')
            || $this->containsAffirmed($normalized, 'right lower')
            || $this->containsAffirmed($normalized, 'lower right')
            || $this->containsAffirmed($normalized, 'right lower quadrant')
            || $this->containsAffirmed($normalized, 'kanang ibaba')
            || $this->containsAffirmed($normalized, 'kanang baba')) {
            return true;
        }

        return $this->mentionsRight($normalized) && $this->mentionsLower($normalized);
    }

    private function severeOrWorseningAbdominalPain(string $normalized): bool
    {
        if (! $this->mentionsAbdomen($normalized)) {
            return false;
        }

        return $this->containsAffirmed($normalized, 'sobrang sakit')
            || $this->containsAffirmed($normalized, 'matinding sakit')
            || $this->containsAffirmed($normalized, 'severe')
            || $this->containsAffirmed($normalized, 'unbearable')
            || $this->containsAffirmed($normalized, 'lumalala')
            || $this->containsAffirmed($normalized, 'worsening')
            || $this->containsAffirmed($normalized, 'getting worse');
    }

    private function seriousInjury(string $normalized): bool
    {
        foreach ([
            'broken bone',
            'broke my',
            'fractured',
            'nabali',
            'nabalian',
            'malalim na hiwa',
            'deep cut',
            'deep wound',
            'malubhang sugat',
            'serious injury',
            'head injury',
            'natamaan ang ulo',
            'malubhang aksidente',
            'serious accident',
            'looks deformed',
        ] as $phrase) {
            if ($this->containsAffirmed($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function pregnancyWarning(string $normalized): bool
    {
        if (! $this->mentionsPregnancy($normalized)) {
            return false;
        }

        foreach ([
            'pagdurugo',
            'bleeding',
            'spotting',
            'nawalan ng tubig',
            'water broke',
            'waters broke',
            'hindi gumagalaw',
            'hindi na gumalaw',
            'no fetal movement',
            'baby is not moving',
            'sobrang sakit ng tiyan',
            'severe abdominal',
            'severe stomach',
        ] as $phrase) {
            if ($this->containsAffirmed($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function concerningChildPresentation(string $normalized): bool
    {
        if (! $this->mentionsChild($normalized)) {
            return false;
        }

        $fever = $this->containsAffirmed($normalized, 'lagnat')
            || $this->containsAffirmed($normalized, 'fever');

        $worrying = $this->containsAffirmed($normalized, 'matamlay')
            || $this->containsAffirmed($normalized, 'hindi umiinom')
            || $this->containsAffirmed($normalized, 'not drinking')
            || $this->containsAffirmed($normalized, 'very sleepy')
            || $this->containsAffirmed($normalized, 'lethargic')
            || $this->containsAffirmed($normalized, 'purple rash')
            || $this->containsAffirmed($normalized, 'limp');

        return $fever && $worrying;
    }

    private function isolatedChestPain(string $normalized): bool
    {
        if ($this->urgentConcerns->isUrgent($normalized)) {
            return false;
        }

        return $this->containsAffirmed($normalized, 'chest pain')
            || $this->containsAffirmed($normalized, 'sumasakit dibdib')
            || $this->containsAffirmed($normalized, 'sumasakit ang dibdib')
            || $this->containsAffirmed($normalized, 'sakit sa dibdib');
    }

    private function mentionsAbdomen(string $normalized): bool
    {
        return $this->containsAffirmed($normalized, 'tiyan')
            || $this->containsAffirmed($normalized, 'stomach')
            || $this->containsAffirmed($normalized, 'abdomen')
            || $this->containsAffirmed($normalized, 'abdominal')
            || $this->containsAffirmed($normalized, 'puson')
            || $this->containsAffirmed($normalized, 'sikmura');
    }

    private function mentionsRight(string $normalized): bool
    {
        return $this->containsAffirmed($normalized, 'kanan')
            || $this->containsAffirmed($normalized, 'right');
    }

    private function mentionsLower(string $normalized): bool
    {
        return $this->containsAffirmed($normalized, 'baba')
            || $this->containsAffirmed($normalized, 'ibaba')
            || $this->containsAffirmed($normalized, 'lower')
            || $this->containsAffirmed($normalized, 'bottom');
    }

    private function mentionsPregnancy(string $normalized): bool
    {
        return $this->containsAffirmed($normalized, 'buntis')
            || $this->containsAffirmed($normalized, 'pregnant')
            || $this->containsAffirmed($normalized, 'pregnancy')
            || $this->containsAffirmed($normalized, 'nagdadalang tao')
            || $this->containsAffirmed($normalized, 'expecting');
    }

    private function mentionsChild(string $normalized): bool
    {
        return (bool) preg_match(
            '/\b(anak|baby|sanggol|infant|toddler|child|years old|year old|taong gulang|buwan gulang|my son|my daughter|ang bata)\b/u',
            $normalized,
        );
    }

    private function containsAffirmed(string $normalized, string $phrase): bool
    {
        return $this->urgentConcerns->containsAffirmed($normalized, $phrase);
    }
}
