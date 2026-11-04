<?php

namespace App\Support;

/**
 * Conservative overlay for possible emergency warning signs.
 *
 * This is not a triage protocol, diagnosis, or clinical scoring tool.
 * It only intercepts high-signal phrases in visitor-reported text so
 * routine clinic booking is not the primary response. Assistant questions
 * and system-prompt examples must not be scanned. Qualified hospital
 * personnel must review it before real-world deployment.
 */
final class AiUrgentConcernDetector
{
    /**
     * Combined phrases that already describe a possible emergency.
     *
     * Isolated common complaints such as headache, dizziness, or chest
     * discomfort without extra warning language are intentionally omitted
     * so ordinary navigation follow-up can still occur.
     *
     * @var list<string>
     */
    private const array PHRASES = [
        'worst headache of my life',
        'sudden severe headache',
        'sudden worst headache',
        'thunderclap headache',
        'cannot speak',
        "can't speak",
        'can not speak',
        'slurred speech',
        'face drooping',
        'face is drooping',
        'one sided weakness',
        'weakness on one side',
        'difficulty speaking',
        'cannot breathe',
        "can't breathe",
        'can not breathe',
        'unable to breathe',
        'severe difficulty breathing',
        'gasping for air',
        'not breathing',
        'lost consciousness',
        'loss of consciousness',
        'passed out',
        'unresponsive',
        'severe uncontrolled bleeding',
        'bleeding that will not stop',
        'bleeding that wont stop',
        "bleeding that won't stop",
        'biglang sobrang sakit ng ulo',
        'biglaang sobrang sakit ng ulo',
        'biglang masakit na masakit ang ulo',
        'atake sa puso',
        'atake sa utak',
        'parang stroke',
        'having a stroke',
        'hindi ako makagalaw',
        'hindi ko maigalaw',
        'hindi makapagsalita',
        'di makapagsalita',
        'hindi ako makapagsalita',
        'namamanhid ang kalahati',
        'hindi ako makahinga',
        'di ako makahinga',
        'hindi makahinga',
        'hirap na hirap huminga',
        'nawalan ng malay',
        'hindi mapigilang pagdurugo',
        'hindi tumitigil ang pagdurugo',
        'may panghihina ako',
        'biglaang panghihina',
        'new weakness',
        'parang mahihimatay',
        'about to collapse',
        'going to collapse',
        'mag-collapse',
        'magcollapse',
        'collapse ako',
        'sudden vision loss',
        'suddenly cannot see',
        'suddenly can not see',
        'sudden blindness',
        'lost my vision',
        'i cannot see anything',
        "i can't see anything",
        'biglang hindi ako makakita',
        'bigla akong hindi makakita',
        'nawala ang paningin',
        'nawalan ng paningin',
        'lost vision in one eye',
        'vision loss in one eye',
        'hindi magising',
        'cannot wake',
        "can't wake",
        'can not wake',
        'having a seizure',
        'kombulsyon',
        'convulsing',
    ];

    /**
     * Symptom cues that a safety message must not present as confirmed
     * unless a visitor message actually reported them.
     *
     * @var list<string>
     */
    private const array ATTRIBUTION_CUES = [
        'panghihina',
        'weakness',
        'slurred',
        'drooping',
        'makapagsalita',
        'cannot speak',
        "can't speak",
        'makahinga',
        'cannot breathe',
        "can't breathe",
        'gasping',
        'nawalan ng malay',
        'loss of consciousness',
        'passed out',
        'matinding sakit',
        'sudden severe',
        'thunderclap',
        'uncontrolled bleeding',
        'pagdurugo',
        'kalahati ng',
    ];

    /**
     * @var list<array{0: string, 1: string}>
     */
    private const array COMBINATIONS = [
        ['chest pain', 'cannot breathe'],
        ['chest pain', "can't breathe"],
        ['chest pain', 'shortness of breath'],
        ['chest pain', 'difficulty breathing'],
        ['chest pain', 'passed out'],
        ['chest pain', 'sweating'],
        ['sumasakit dibdib', 'hindi makahinga'],
        ['sumasakit ang dibdib', 'hirap huminga'],
        ['sumasakit dibdib ko', 'nahihilo'],
        ['chest pain', 'hirap huminga'],
        ['sobrang sakit ng ulo', 'bigla'],
        ['matinding sakit ng ulo', 'bigla'],
        ['severe headache', 'sudden'],
    ];

    public function containsAffirmed(string $normalized, string $phrase): bool
    {
        return $this->hasAffirmedOccurrence($normalized, $this->normalize($phrase));
    }

    public function isUrgent(string $text): bool
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return false;
        }

        foreach (self::PHRASES as $phrase) {
            if ($this->hasAffirmedOccurrence($normalized, $this->normalize($phrase))) {
                return true;
            }
        }

        $suddenText = $this->withoutIntermittentMarkers($normalized);

        foreach (self::COMBINATIONS as [$first, $second]) {
            $haystack = $this->isSuddenCue($second) || $this->isSuddenCue($first)
                ? $suddenText
                : $normalized;

            if ($this->hasAffirmedOccurrence($haystack, $this->normalize($first))
                && $this->hasAffirmedOccurrence($haystack, $this->normalize($second))) {
                return true;
            }
        }

        return $this->looksLikeSuddenSevereHeadache($suddenText);
    }

    public function attributesUnreportedSymptoms(string $assistantMessage, string $patientText): bool
    {
        $assistant = $this->normalize($assistantMessage);
        $patient = $this->normalize($patientText);

        if ($assistant === '') {
            return false;
        }

        foreach (self::ATTRIBUTION_CUES as $cue) {
            if (str_contains($assistant, $cue) && ! $this->hasAffirmedOccurrence($patient, $cue)) {
                return true;
            }
        }

        return false;
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\p{L}\p{N}\s\']+/u', ' ', $text) ?? $text;

        return AiVisitorReply::applyInformalSpelling(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    private function withoutIntermittentMarkers(string $normalized): string
    {
        $stripped = preg_replace('/\bbigla(?:ng)?\s+bigla(?:ng)?\b/u', ' ', $normalized) ?? $normalized;
        $stripped = preg_replace('/\bon and off\b/u', ' ', $stripped) ?? $stripped;
        $stripped = preg_replace('/\bintermittent\b/u', ' ', $stripped) ?? $stripped;

        return trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped);
    }

    private function looksLikeSuddenSevereHeadache(string $suddenText): bool
    {
        if (! $this->hasSuddenMarker($suddenText)) {
            return false;
        }

        $head = $this->containsAffirmed($suddenText, 'ulo')
            || $this->containsAffirmed($suddenText, 'headache');

        if (! $head) {
            return false;
        }

        return $this->containsAffirmed($suddenText, 'sobrang tindi')
            || $this->containsAffirmed($suddenText, 'napakatindi')
            || $this->containsAffirmed($suddenText, 'sobrang sakit')
            || $this->containsAffirmed($suddenText, 'matinding sakit')
            || $this->containsAffirmed($suddenText, 'worst')
            || $this->containsAffirmed($suddenText, 'thunderclap');
    }

    private function hasSuddenMarker(string $normalized): bool
    {
        return (bool) preg_match('/\bbigla(?:ng|an)?\b/u', $normalized)
            || $this->containsAffirmed($normalized, 'sudden');
    }

    private function isSuddenCue(string $phrase): bool
    {
        return str_contains($phrase, 'bigla') || str_contains($phrase, 'sudden');
    }

    private function hasAffirmedOccurrence(string $normalized, string $phrase): bool
    {
        $offset = 0;
        $length = mb_strlen($phrase);

        if ($length === 0) {
            return false;
        }

        while (($position = mb_strpos($normalized, $phrase, $offset)) !== false) {
            if ($this->isWholePhrase($normalized, $position, $length)) {
                $windowStart = max(0, $position - 48);
                $prefix = trim(mb_substr($normalized, $windowStart, $position - $windowStart));

                if (! $this->prefixNegates($prefix)) {
                    return true;
                }
            }

            $offset = $position + 1;
        }

        return false;
    }

    private function isWholePhrase(string $normalized, int $position, int $length): bool
    {
        $before = $position === 0 ? '' : mb_substr($normalized, $position - 1, 1);
        $afterPosition = $position + $length;
        $after = $afterPosition >= mb_strlen($normalized) ? '' : mb_substr($normalized, $afterPosition, 1);

        return $this->isBoundaryChar($before) && $this->isBoundaryChar($after);
    }

    private function isBoundaryChar(string $char): bool
    {
        return $char === '' || (bool) preg_match('/[^\p{L}\p{N}]/u', $char);
    }

    private function prefixNegates(string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }

        return (bool) preg_match(
            '/\b(wala|walang|wala akong|hindi|hindi ako|di ako|not|no|without|dont|do not|does not|never)\b/u',
            $prefix,
        );
    }
}
