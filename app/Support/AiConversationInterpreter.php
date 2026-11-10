<?php

namespace App\Support;

/**
 * Derives optional routing facts from the current visit.
 *
 * This is original MediGuide conversation memory, not a copy of another
 * company's interview engine or clinical decision rules. It does not
 * diagnose, score urgency, or invent unreported symptoms.
 */
final class AiConversationInterpreter
{
    public const string TOPIC_DURATION = 'duration';

    public const string TOPIC_LOCATION = 'location';

    public const string TOPIC_ONSET = 'onset';

    public const string TOPIC_SEVERITY = 'severity';

    public const string TOPIC_FREQUENCY = 'frequency';

    public const string TOPIC_ASSOCIATED = 'associated';

    public const string TOPIC_QUADRANT = 'quadrant';

    public const string TOPIC_APPEARANCE = 'appearance';

    public const string TOPIC_INJURY = 'injury';

    public static function interpret(AiConversationState $state, string $latestConcern): AiConversationFacts
    {
        $opening = AiVisitorReply::firstStatedConcern($state->openingConcern, $state->turns, $latestConcern) ?? '';

        $kind = self::concernKind($opening);
        $anchor = self::anchorFor($kind);
        $lastTopic = null;
        $facts = [
            'location' => null,
            'duration' => null,
            'onset' => null,
            'severity' => null,
            'frequency' => null,
            'associated' => [],
            'denied' => [],
            'unknown' => [],
            'answered' => [],
        ];

        foreach ($state->turns as $turn) {
            if ($turn['role'] === 'assistant') {
                $lastTopic = self::topicOf($turn['content']);
                continue;
            }

            if ($turn['role'] === 'user') {
                $facts = self::applyMessage($facts, $turn['content'], $lastTopic, $kind, $anchor);
            }
        }

        $facts = self::applyMessage($facts, $latestConcern, $lastTopic, $kind, $anchor);
        $answered = $facts['answered'];

        if ($kind === 'abdominal' && self::hasQuadrant($facts['location'])) {
            $answered = self::rememberTopic($answered, self::TOPIC_QUADRANT);
        }

        if ($kind === 'back' && self::hasSpecificBackLocation($facts['location'])) {
            $answered = self::rememberTopic($answered, self::TOPIC_LOCATION);
        }

        $missing = self::missingPriority($kind, $answered, $facts['location']);

        return new AiConversationFacts(
            primaryConcern: $opening === '' ? null : self::bounded($opening),
            concernKind: $kind,
            bodyLocation: $facts['location'],
            locationAnchor: $anchor,
            duration: $facts['duration'],
            onset: $facts['onset'],
            severity: $facts['severity'],
            frequency: $facts['frequency'],
            associated: array_values(array_unique($facts['associated'])),
            denied: array_values(array_unique($facts['denied'])),
            unknown: array_values(array_unique($facts['unknown'])),
            answeredTopics: array_values(array_unique($answered)),
            lastQuestionTopic: $lastTopic,
            replyLanguage: self::resolveReplyLanguage($state, $latestConcern, $opening),
            missingPriority: $missing,
        );
    }

    public static function topicOf(string $question): ?string
    {
        $normalized = AiVisitorReply::normalize($question);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\b(itsura|kumakalat|spread|appearance|look like|nagbabago)\b/u', $normalized)) {
            return self::TOPIC_APPEARANCE;
        }

        if (preg_match('/\b(tama|aksidente|nabalian|injury|injured|accident|fell|nahulog)\b/u', $normalized)) {
            return self::TOPIC_INJURY;
        }

        if (preg_match('/\b(itaas|baba ng tiyan|higher up|lower down|upper or lower|gitna|middle)\b/u', $normalized)) {
            if (preg_match('/\b(likod|back)\b/u', $normalized) && ! preg_match('/\b(tiyan|abdomen|ulo|head)\b/u', $normalized)) {
                return self::TOPIC_LOCATION;
            }

            return self::TOPIC_QUADRANT;
        }

        if (preg_match('/\b(kailan|katagal|when did|how long|gaano na|gaano katagal)\b/u', $normalized)) {
            return self::TOPIC_DURATION;
        }

        if (preg_match('/\b(biglaan|unti[-\s]?unti|sudden or|start suddenly|did this start|did the pain start)\b/u', $normalized)) {
            return self::TOPIC_ONSET;
        }

        if (preg_match('/\b(minsan|frequency|how often|gaano kadalas|palagi)\b/u', $normalized)) {
            return self::TOPIC_FREQUENCY;
        }

        if (preg_match('/\b(gaano(?: po)? (?:katindi|kasakit|kalakas)|how strong|how severe|how bad|how painful)\b/u', $normalized)
            && ! preg_match('/\b(biglaan|unti-unti|sudden)\b/u', $normalized)) {
            return self::TOPIC_SEVERITY;
        }

        if (preg_match('/\b(saang parte|saan po|where do you feel|which part|nasaan)\b/u', $normalized)) {
            return self::TOPIC_LOCATION;
        }

        if (preg_match('/\b(iba pa|kasabay|along with|anything else|other symptoms)\b/u', $normalized)) {
            return self::TOPIC_ASSOCIATED;
        }

        return null;
    }

    public static function languageOf(string $text): string
    {
        $normalized = AiVisitorReply::normalize($text);

        if ($normalized === '') {
            return 'english';
        }

        $filipino = preg_match_all(
            '/\b(po|opo|ako|ko|ang|masakit|masaket|sakit|hindi|wala|tiyan|ulo|lagnat|ubo|kahapon|kanina|buntis|anak|tuhod|tenga|mata|bigla|unti|araw|saan|gaano|kanan|kaliwa|bandang|nararamdaman|ayaw|ewan|nilalagnat|nahihilo|likod|mga|ilang|buwan|matagal|tagal|rin|naman|parang|siya|ito|yung|yan|mabigat|nangangalay|kasakit)\b/u',
            $normalized,
        );
        $english = preg_match_all(
            '/\b(the|have|has|been|my|cough|headache|pain|fever|since|yesterday|today|where|stomach|child|please|this|that|with|already|happening|noticing)\b/u',
            $normalized,
        );

        $filipino = is_int($filipino) ? $filipino : 0;
        $english = is_int($english) ? $english : 0;

        if ($filipino > 0 && $english > 0) {
            return 'taglish';
        }

        if ($filipino > 0) {
            return 'filipino';
        }

        return 'english';
    }

    public static function resolveReplyLanguage(AiConversationState $state, string $latestConcern, string $opening): string
    {
        $explicit = AiVisitorReply::explicitLanguagePreference($latestConcern);

        if ($explicit !== null) {
            return $explicit;
        }

        if ($state->replyLanguage !== null && ! self::hasStrongLanguageSignal($latestConcern)) {
            return $state->replyLanguage;
        }

        $detected = self::languageOf($latestConcern !== '' ? $latestConcern : $opening);

        if ($state->replyLanguage !== null
            && $detected === 'english'
            && self::looksLikeBorrowedEnglish($latestConcern)
            && ($state->replyLanguage === 'filipino' || $state->replyLanguage === 'taglish')) {
            return $state->replyLanguage;
        }

        if (self::hasStrongLanguageSignal($latestConcern)) {
            return $detected;
        }

        if ($state->replyLanguage !== null) {
            return $state->replyLanguage;
        }

        return self::languageOf($opening !== '' ? $opening : $latestConcern);
    }

    public static function hasStrongLanguageSignal(string $text): bool
    {
        if (AiVisitorReply::explicitLanguagePreference($text) !== null) {
            return true;
        }

        $normalized = AiVisitorReply::normalize($text);

        if ($normalized === '' || str_word_count($normalized) <= 2) {
            return false;
        }

        $filipino = preg_match_all(
            '/\b(po|opo|ako|ko|ang|masakit|masaket|sakit|hindi|wala|tiyan|ulo|likod|mga|ilang|buwan|matagal|tagal|naman|parang|siya|nararamdaman|gaano|saan)\b/u',
            $normalized,
        );
        $english = preg_match_all(
            '/\b(the|have|has|been|my|cough|headache|please|this|that|where|noticing|happening|pain|fever|stomach)\b/u',
            $normalized,
        );

        $filipino = is_int($filipino) ? $filipino : 0;
        $english = is_int($english) ? $english : 0;

        return $filipino >= 1 || $english >= 2;
    }

    private static function looksLikeBorrowedEnglish(string $text): bool
    {
        $normalized = AiVisitorReply::normalize($text);

        if ($normalized === '') {
            return false;
        }

        $englishFunction = preg_match_all(
            '/\b(the|have|has|been|my|please|this|that|where|noticing|happening)\b/u',
            $normalized,
        );

        if (is_int($englishFunction) && $englishFunction >= 2) {
            return false;
        }

        return (bool) preg_match(
            '/\b(since|yesterday|today|last (?:week|month|night)|months?|weeks?|days?|already|ago)\b/u',
            $normalized,
        );
    }

    public static function prefersFilipino(string $text): bool
    {
        $language = self::languageOf($text);

        return $language === 'filipino' || $language === 'taglish';
    }

    public static function isPainConcern(AiConversationFacts $facts): bool
    {
        if (in_array($facts->concernKind, [
            'headache',
            'abdominal',
            'joint',
            'back',
            'injury',
            'ear',
            'eye',
        ], true)) {
            return true;
        }

        $text = $facts->primaryConcern ?? '';

        if (preg_match('/\b(dibdib|chest|ubo|cough|lagnat|fever)\b/iu', $text)) {
            return false;
        }

        return (bool) preg_match('/\b(sakit|masakit|masaket|pain|ache)\b/iu', $text)
            && ! in_array($facts->concernKind, ['cough', 'fever', 'pediatric', 'pregnancy', 'dizziness', 'skin', 'throat'], true);
    }

    /**
     * @param  array{
     *     location: ?string,
     *     duration: ?string,
     *     onset: ?string,
     *     severity: ?string,
     *     frequency: ?string,
     *     associated: list<string>,
     *     denied: list<string>,
     *     unknown: list<string>,
     *     answered: list<string>
     * }  $facts
     * @return array{
     *     location: ?string,
     *     duration: ?string,
     *     onset: ?string,
     *     severity: ?string,
     *     frequency: ?string,
     *     associated: list<string>,
     *     denied: list<string>,
     *     unknown: list<string>,
     *     answered: list<string>
     * }
     */
    private static function applyMessage(array $facts, string $message, ?string $lastTopic, string $kind, ?string $anchor): array
    {
        $message = trim(AiVisitorReply::withoutLanguageInstruction($message));

        if ($message === '') {
            return $facts;
        }

        if (AiVisitorReply::declinedOrUnknown($message)) {
            $topic = $lastTopic ?? 'unspecified';
            $facts['unknown'][] = $topic;
            $facts['answered'] = self::rememberTopic($facts['answered'], $topic);

            return $facts;
        }

        if (AiVisitorReply::negativeConfirmation($message)) {
            $topic = $lastTopic ?? 'asked_detail';
            $facts['denied'][] = $topic;
            $facts['answered'] = self::rememberTopic($facts['answered'], $topic);

            return $facts;
        }

        if (AiVisitorReply::positiveConfirmation($message)) {
            $topic = $lastTopic ?? 'asked_detail';
            $facts['answered'] = self::rememberTopic($facts['answered'], $topic);

            return $facts;
        }

        if (self::isIntermittent($message)) {
            $facts['onset'] = 'intermittent';
            $facts['answered'] = self::rememberTopic($facts['answered'], self::TOPIC_ONSET);
        }

        if (self::isFrequency($message)) {
            $facts['frequency'] = self::bounded($message);
            $facts['answered'] = self::rememberTopic($facts['answered'], self::TOPIC_FREQUENCY);
        }

        $duration = self::extractDuration($message);

        if ($duration !== null) {
            $facts['duration'] = $duration;
            $facts['answered'] = self::rememberTopic($facts['answered'], self::TOPIC_DURATION);
        } elseif ($lastTopic === self::TOPIC_DURATION && self::looksLikeDurationAnswer($message)) {
            $facts['duration'] = self::bounded($message);
            $facts['answered'] = self::rememberTopic($facts['answered'], self::TOPIC_DURATION);
        }

        $location = self::extractLocation($message, $kind, $anchor);

        if ($location !== null) {
            $facts['location'] = $location;

            if ($kind !== 'back' || self::hasSpecificBackLocation($location)) {
                $facts['answered'] = self::rememberTopic($facts['answered'], self::TOPIC_LOCATION);
            }

            if (self::hasQuadrant($location)) {
                $facts['answered'] = self::rememberTopic($facts['answered'], self::TOPIC_QUADRANT);
            }
        } elseif ($lastTopic === self::TOPIC_LOCATION || $lastTopic === self::TOPIC_QUADRANT) {
            $bound = self::extractLocation($message, $kind, $anchor) ?? self::relativeLocation($message, $kind, $anchor);

            if ($bound !== null) {
                $facts['location'] = $bound;
                $facts['answered'] = self::rememberTopic($facts['answered'], $lastTopic);
            }
        }

        $onset = self::extractOnset($message);

        if ($onset !== null && $facts['onset'] !== 'intermittent') {
            $facts['onset'] = $onset;
            $facts['answered'] = self::rememberTopic($facts['answered'], self::TOPIC_ONSET);
        }

        $severity = self::extractSeverity($message);

        if ($lastTopic === self::TOPIC_SEVERITY) {
            if (preg_match('/^\s*(\d{1,2})\s*$/u', $message, $rating) && (int) $rating[1] <= 10) {
                $facts['severity'] = $rating[1].'/10';
            } else {
                $facts['severity'] = $severity ?? self::bounded($message);
            }
            $facts['answered'] = self::rememberTopic($facts['answered'], self::TOPIC_SEVERITY);
        } elseif ($severity !== null) {
            $facts['severity'] = $severity;
            $facts['answered'] = self::rememberTopic($facts['answered'], self::TOPIC_SEVERITY);
        }

        return $facts;
    }

    public static function concernKind(string $text): string
    {
        $normalized = AiVisitorReply::normalize($text);
        $kinds = [];

        if (preg_match('/\b(anak|child|year old|taong gulang|baby|infant|toddler)\b/u', $normalized)) {
            return 'pediatric';
        }

        if (preg_match('/\b(buntis|pregnant|pregnancy)\b/u', $normalized)) {
            return 'pregnancy';
        }

        if (preg_match('/\b(headache|sakit ng ulo|masakit ulo|masaket ulo)\b/u', $normalized)
            || (preg_match('/\bulo\b/u', $normalized) && preg_match('/\b(sakit|masakit|masaket|pain)\b/u', $normalized))) {
            $kinds[] = 'headache';
        }

        if (preg_match('/\b(tiyan|stomach|abdomen|abdominal|puson|sikmura)\b/u', $normalized)) {
            $kinds[] = 'abdominal';
        }

        if (preg_match('/\b(ubo|cough)\b/u', $normalized)) {
            $kinds[] = 'cough';
        }

        if (preg_match('/\b(lalamunan|throat|sore throat)\b/u', $normalized)) {
            $kinds[] = 'throat';
        }

        if (preg_match('/\b(lagnat|nilalagnat|fever)\b/u', $normalized)) {
            $kinds[] = 'fever';
        }

        if (preg_match('/\b(tuhod|joint|kasukasuan|siko|wrist|ankle)\b/u', $normalized)) {
            $kinds[] = 'joint';
        }

        if (preg_match('/\b(likod|back pain|upper back|lower back)\b/u', $normalized)
            && ! in_array('headache', $kinds, true)) {
            $kinds[] = 'back';
        }

        if (preg_match('/\b(rash|kati|balat|skin|bugas)\b/u', $normalized)) {
            $kinds[] = 'skin';
        }

        if (preg_match('/\b(tenga|ear)\b/u', $normalized)) {
            $kinds[] = 'ear';
        }

        if (preg_match('/\b(mata|eye|vision|paningin)\b/u', $normalized)
            && ! preg_match('/\bnawalan ng paningin\b/u', $normalized)) {
            $kinds[] = 'eye';
        }

        if (preg_match('/\b(nahulog|injury|injured|nabalian|broke|deformed)\b/u', $normalized)) {
            $kinds[] = 'injury';
        }

        if (preg_match('/\b(hilo|dizzy|dizziness|nahihilo)\b/u', $normalized)) {
            $kinds[] = 'dizziness';
        }

        $kinds = array_values(array_unique($kinds));

        if (count($kinds) > 1) {
            return 'multiple';
        }

        return $kinds[0] ?? 'other';
    }

    private static function anchorFor(string $kind): ?string
    {
        return match ($kind) {
            'headache' => 'head',
            'abdominal' => 'abdomen',
            'back' => 'back',
            'joint' => 'joint',
            'chest' => 'chest',
            'skin' => 'skin',
            'ear' => 'ear',
            'eye' => 'eye',
            default => null,
        };
    }

    private static function extractDuration(string $text): ?string
    {
        $normalized = AiVisitorReply::normalize($text);

        if ($normalized === '') {
            return null;
        }

        $hasUnit = preg_match('/\b(buwan|months?|linggo|weeks?|araw|days?|hours?|oras|taon|years?)\b/u', $normalized) === 1;
        $hasRelative = preg_match('/\b(kahapon|yesterday|kanina|ngayon|today|kagabi|last (?:week|month|night)|this morning|this afternoon|since|ago)\b/u', $normalized) === 1;
        $hasApproximate = preg_match('/\b(matagal|tagal|ilang|for a while|a while now|long time|quite some time)\b/u', $normalized) === 1;

        if ($hasUnit || $hasRelative || $hasApproximate) {
            return self::bounded($text);
        }

        return null;
    }

    private static function looksLikeDurationAnswer(string $text): bool
    {
        if (self::extractDuration($text) !== null) {
            return true;
        }

        $normalized = AiVisitorReply::normalize($text);

        return (bool) preg_match('/\b(araw|day|week|hour|oras|linggo|buwan|month|taon|year|tagal|matagal|ilang|ago|since|while)\b/iu', $normalized);
    }

    private static function extractLocation(string $text, string $kind, ?string $anchor): ?string
    {
        $relative = self::relativeLocation($text, $kind, $anchor);

        if ($relative !== null) {
            return $relative;
        }

        $normalized = AiVisitorReply::normalize($text);

        if (preg_match('/\b(tuhod|knee)\b/u', $normalized)) {
            return 'knee';
        }

        if (preg_match('/\b(braso|arm)\b/u', $normalized)) {
            return 'arm';
        }

        if (preg_match('/\b(tenga|ear)\b/u', $normalized)) {
            return 'ear';
        }

        if (preg_match('/\b(mata|eye)\b/u', $normalized) && $kind === 'eye') {
            return 'eye';
        }

        if ($kind === 'skin' && preg_match('/\b(braso|arm|leg|binti|mukha|face|likod|back)\b/u', $normalized, $match)) {
            return $match[1];
        }

        if ($kind === 'abdominal' && preg_match('/\b(tiyan|stomach|abdomen)\b/u', $normalized)) {
            return 'abdomen';
        }

        return null;
    }

    private static function relativeLocation(string $text, string $kind, ?string $anchor): ?string
    {
        $normalized = AiVisitorReply::normalize($text);
        $side = null;

        if (preg_match('/\b(kanan|right)\b/u', $normalized)) {
            $side = 'right';
        } elseif (preg_match('/\b(kaliwa|left)\b/u', $normalized)) {
            $side = 'left';
        }

        $level = null;

        if (preg_match('/\b(baba|ibaba|lower|puson|bottom)\b/u', $normalized)) {
            $level = 'lower';
        } elseif (preg_match('/\b(itaas|taas|upper)\b/u', $normalized)) {
            $level = 'upper';
        } elseif (preg_match('/\b(gitna|middle)\b/u', $normalized)) {
            $level = 'middle';
        }

        if ($kind === 'headache' || $anchor === 'head') {
            if (preg_match('/\b(likod|back)\b/u', $normalized)) {
                return $side ? "{$side} back of the head" : 'back of the head';
            }

            if (preg_match('/\b(harap|noo|front|forehead)\b/u', $normalized)) {
                return $side ? "{$side} front of the head" : 'front of the head';
            }

            if (preg_match('/\b(gilid|side|temple)\b/u', $normalized)) {
                return $side ? "{$side} side of the head" : 'side of the head';
            }

            if ($side !== null) {
                return "{$side} side of the head";
            }

            return null;
        }

        if ($kind === 'abdominal' || $anchor === 'abdomen') {
            if ($side !== null && $level !== null) {
                return "{$side} {$level} abdomen";
            }

            if ($level !== null) {
                return "{$level} abdomen";
            }

            if (preg_match('/\b(puson)\b/u', $normalized)) {
                return 'lower abdomen';
            }

            if ($side !== null) {
                return "{$side} abdomen";
            }
        }

        if ($kind === 'back' || $anchor === 'back') {
            if ($side !== null && $level !== null) {
                return "{$side} {$level} back";
            }

            if ($level !== null) {
                return "{$level} back";
            }

            if ($side !== null) {
                return "{$side} back";
            }

            if (preg_match('/\b(likod|back)\b/u', $normalized)) {
                return 'back';
            }
        }

        if ($side !== null) {
            return $side;
        }

        return null;
    }

    private static function hasQuadrant(?string $location): bool
    {
        if (! is_string($location)) {
            return false;
        }

        return str_contains($location, 'lower') || str_contains($location, 'upper');
    }

    private static function hasSpecificBackLocation(?string $location): bool
    {
        if (! is_string($location) || $location === '' || $location === 'back') {
            return false;
        }

        return str_contains($location, 'upper')
            || str_contains($location, 'lower')
            || str_contains($location, 'middle')
            || str_contains($location, 'left')
            || str_contains($location, 'right');
    }

    private static function extractOnset(string $text): ?string
    {
        $normalized = AiVisitorReply::normalize($text);

        if (self::isIntermittent($text)) {
            return 'intermittent';
        }

        if (preg_match('/\b(unti[-\s]?unti|gradually|gradual)\b/u', $normalized)) {
            return 'gradual';
        }

        if (preg_match('/\bbigla(?:ng|an)?\b/u', $normalized) && ! self::isIntermittent($text)) {
            return 'sudden';
        }

        if (preg_match('/\bsudden\b/u', $normalized) && ! self::isIntermittent($text)) {
            return 'sudden';
        }

        return null;
    }

    private static function extractSeverity(string $text): ?string
    {
        $normalized = AiVisitorReply::normalize($text);

        if (preg_match('/\b(\d{1,2})\s*(?:out of|\/|sa)\s*10\b/iu', $text, $match) && (int) $match[1] <= 10) {
            return $match[1].'/10';
        }

        if (preg_match('/\b(\d{1,2})\s+out of\s+10\b/u', $normalized, $match) && (int) $match[1] <= 10) {
            return $match[1].'/10';
        }

        if (preg_match('/\b(minsan matindi|minsan masakit|sometimes severe|sometimes intense)\b/u', $normalized)) {
            return 'variable';
        }

        if (preg_match('/\b(napakatindi|sobrang tindi|sobrang sakit|matinding sakit|very severe|unbearable|worst)\b/u', $normalized)
            || preg_match('/\bsevere pain\b/u', $normalized)
            || preg_match('/\bsevere\b/u', $normalized)) {
            return 'severe';
        }

        if (preg_match('/\b(di naman|hindi naman|not that|not really|not too).{0,50}\b(kasakit|masakit|painful|sakit)\b/u', $normalized)) {
            return 'mild';
        }

        if (preg_match('/\b(medyo masakit|medyo lang|mild pain|mild|slight|konti)\b/u', $normalized)) {
            return 'mild';
        }

        if (preg_match('/\b(mabigat|nangangalay|heaviness|aching|sore)\b/u', $normalized)) {
            return 'qualitative';
        }

        return null;
    }

    public static function isIntermittent(string $text): bool
    {
        $normalized = AiVisitorReply::normalize($text);

        return (bool) preg_match('/\b(bigla bigla|bigla-bigla|on and off|intermittent|minsan minsan)\b/u', $normalized);
    }

    public static function isFrequency(string $text): bool
    {
        $normalized = AiVisitorReply::normalize($text);

        return (bool) preg_match('/\b(minsan lang|sometimes|occasionally|palagi|all the time|madalas)\b/u', $normalized);
    }

    /**
     * @param  list<string>  $answered
     * @return list<string>
     */
    private static function rememberTopic(array $answered, string $topic): array
    {
        if (! in_array($topic, $answered, true)) {
            $answered[] = $topic;
        }

        return $answered;
    }

    /**
     * @param  list<string>  $answered
     */
    private static function missingPriority(string $kind, array $answered, ?string $location): ?string
    {
        $has = static fn (string $topic): bool => in_array($topic, $answered, true);

        if ($kind === 'abdominal' && $location !== null && preg_match('/\b(right|left)\b/', $location) && ! $has(self::TOPIC_QUADRANT)) {
            return self::TOPIC_QUADRANT;
        }

        $order = match ($kind) {
            'headache' => [self::TOPIC_DURATION, self::TOPIC_LOCATION, self::TOPIC_ONSET, self::TOPIC_SEVERITY, self::TOPIC_ASSOCIATED],
            'cough' => [self::TOPIC_DURATION, self::TOPIC_ASSOCIATED],
            'fever', 'pediatric', 'pregnancy', 'dizziness' => [self::TOPIC_DURATION, self::TOPIC_ASSOCIATED],
            'abdominal' => [self::TOPIC_DURATION, self::TOPIC_LOCATION, self::TOPIC_QUADRANT, self::TOPIC_ONSET, self::TOPIC_SEVERITY],
            'joint' => [self::TOPIC_DURATION, self::TOPIC_INJURY, self::TOPIC_SEVERITY, self::TOPIC_ASSOCIATED],
            'skin' => [self::TOPIC_LOCATION, self::TOPIC_DURATION, self::TOPIC_APPEARANCE],
            'ear', 'eye' => [self::TOPIC_DURATION, self::TOPIC_SEVERITY, self::TOPIC_ASSOCIATED],
            'back' => [self::TOPIC_DURATION, self::TOPIC_LOCATION, self::TOPIC_SEVERITY, self::TOPIC_ONSET, self::TOPIC_ASSOCIATED],
            'injury' => [self::TOPIC_LOCATION, self::TOPIC_INJURY, self::TOPIC_SEVERITY],
            'multiple' => ['primary', self::TOPIC_DURATION],
            default => [self::TOPIC_DURATION, self::TOPIC_LOCATION, self::TOPIC_ONSET, self::TOPIC_SEVERITY],
        };

        foreach ($order as $topic) {
            if (! $has($topic)) {
                return $topic;
            }
        }

        return null;
    }

    private static function bounded(string $text): string
    {
        $text = trim($text);

        if (mb_strlen($text) > 200) {
            return mb_substr($text, 0, 200);
        }

        return $text;
    }
}
