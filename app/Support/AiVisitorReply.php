<?php

namespace App\Support;

/**
 * Lightweight interpretation of a visitor follow-up reply.
 *
 * Used only to stop pressuring people who decline or do not know an answer.
 * It does not classify symptoms or choose a clinic.
 */
final class AiVisitorReply
{
    /**
     * @var list<string>
     */
    private const array EXACT = [
        'skip',
        'pass',
        'ewan',
        'ewan ko',
        'next',
    ];

    /**
     * @var list<string>
     */
    private const array PHRASES = [
        'i dont know',
        'i do not know',
        'dont know',
        'do not know',
        'not sure',
        'i am not sure',
        'im not sure',
        'i prefer not to say',
        'prefer not to answer',
        'prefer not to say',
        'i dont want to answer',
        'i do not want to answer',
        'i dont want to say',
        'no comment',
        'hindi ko alam',
        'di ko alam',
        'ayaw ko sagutin',
        'ayaw ko sabihin',
        'ayaw ko mag sagot',
        'ayaw kong sagutin',
        'wag na',
        'huwag na',
    ];

    /**
     * Requests for help choosing a service. These are not refusals to answer.
     *
     * @var list<string>
     */
    private const array SPECIALIST_NAVIGATION = [
        'not sure which specialist',
        'not sure which doctor',
        'not sure which clinic',
        'not sure which department',
        'not sure what specialist',
        'not sure what doctor',
        'dont know which specialist',
        'do not know which specialist',
        'dont know which doctor',
        'do not know which doctor',
        'dont know what specialist',
        'dont know what doctor',
        'which specialist',
        'which doctor',
        'which department',
        'what specialist',
        'what kind of doctor',
        'what kind of specialist',
        'what type of doctor',
        'what type of specialist',
        'help me choose a specialist',
        'help me choose a doctor',
        'help me find a specialist',
        'help me find a doctor',
        'anong specialist',
        'anong doktor',
        'sinong doktor',
        'sinong specialist',
        'aling specialist',
        'aling doktor',
    ];

    /**
     * Words left behind by a specialist-choice request. These are not symptoms.
     *
     * @var array<string, true>
     */
    private const array NAVIGATION_STOPWORDS = [
        'i' => true,
        'im' => true,
        'me' => true,
        'my' => true,
        'a' => true,
        'an' => true,
        'the' => true,
        'this' => true,
        'that' => true,
        'it' => true,
        'for' => true,
        'to' => true,
        'and' => true,
        'or' => true,
        'of' => true,
        'should' => true,
        'can' => true,
        'could' => true,
        'would' => true,
        'please' => true,
        'help' => true,
        'see' => true,
        'find' => true,
        'choose' => true,
        'need' => true,
        'want' => true,
        'know' => true,
        'do' => true,
        'not' => true,
        'sure' => true,
        'which' => true,
        'what' => true,
        'kind' => true,
        'type' => true,
        'doctor' => true,
        'specialist' => true,
        'clinic' => true,
        'department' => true,
        'am' => true,
        'is' => true,
        'are' => true,
        'be' => true,
        'with' => true,
        'ako' => true,
        'ko' => true,
        'ang' => true,
        'ng' => true,
        'sa' => true,
        'na' => true,
        'po' => true,
        'ba' => true,
        'lang' => true,
        'mga' => true,
        'kung' => true,
        'hindi' => true,
        'wala' => true,
        'alam' => true,
        'kailangan' => true,
        'anong' => true,
        'sinong' => true,
        'aling' => true,
        'doktor' => true,
        'ninyo' => true,
        'kayo' => true,
        'akong' => true,
        'yung' => true,
        'ito' => true,
        'para' => true,
        'sana' => true,
        'paki' => true,
        'pakiusap' => true,
        'magtagalog' => true,
        'ka' => true,
        'tagalog' => true,
        'filipino' => true,
        'english' => true,
        'speak' => true,
        'in' => true,
        'rin' => true,
        'naman' => true,
        'may' => true,
        'parang' => true,
    ];

    public static function negativeConfirmation(string $text): bool
    {
        $normalized = self::normalize(self::withoutLanguageInstruction($text));

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, [
            'no',
            'nope',
            'none',
            'hindi',
            'hindi po',
            'hindi naman',
            'hinde',
            'hinde naman',
            'wala',
            'wala po',
            'wala naman',
            'none of those',
            'none of that',
            'not really',
        ], true);
    }

    public static function positiveConfirmation(string $text): bool
    {
        $normalized = self::normalize(self::withoutLanguageInstruction($text));

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, [
            'yes',
            'yeah',
            'yep',
            'oo',
            'oo po',
            'opo',
            'tama',
            'tama po',
            'yes po',
        ], true);
    }

    public static function declinedOrUnknown(string $text): bool
    {
        $normalized = self::normalize(self::withoutLanguageInstruction($text));

        if ($normalized === '') {
            return false;
        }

        if (self::requestsSpecialistNavigation($text)) {
            return false;
        }

        if (in_array($normalized, self::EXACT, true)) {
            return true;
        }

        foreach (self::PHRASES as $phrase) {
            if ($normalized === $phrase || str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    public static function requestsSpecialistNavigation(string $text): bool
    {
        $normalized = self::normalize(self::withoutLanguageInstruction($text));

        if ($normalized === '') {
            return false;
        }

        foreach (self::SPECIALIST_NAVIGATION as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{role?: mixed, content?: mixed}>  $turns
     */
    public static function firstStatedConcern(?string $opening, array $turns, string $latest): ?string
    {
        $candidates = [];

        if (is_string($opening) && trim($opening) !== '') {
            $candidates[] = $opening;
        }

        foreach ($turns as $turn) {
            if (($turn['role'] ?? null) === 'user' && is_string($turn['content'] ?? null)) {
                $candidates[] = $turn['content'];
            }
        }

        $candidates[] = $latest;

        foreach ($candidates as $candidate) {
            if (self::statesHealthConcern($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function statesHealthConcern(string $text): bool
    {
        if (self::normalize($text) === '') {
            return false;
        }

        if (! self::requestsSpecialistNavigation($text)) {
            return true;
        }

        return self::hasDescribedConcern(self::withoutSpecialistNavigation($text));
    }

    /**
     * True when text still describes a concern after navigation wording is removed.
     * This does not match a symptom dictionary. Leftover helper words such as
     * "I need" do not count as a described concern.
     */
    private static function hasDescribedConcern(string $text): bool
    {
        $normalized = self::normalize(self::withoutLanguageInstruction($text));

        if ($normalized === '') {
            return false;
        }

        foreach (preg_split('/\s+/u', $normalized) ?: [] as $token) {
            if ($token !== '' && ! isset(self::NAVIGATION_STOPWORDS[$token])) {
                return true;
            }
        }

        return false;
    }

    public static function withoutSpecialistNavigation(string $text): string
    {
        $normalized = self::normalize($text);
        $phrases = self::SPECIALIST_NAVIGATION;
        usort($phrases, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        foreach ($phrases as $phrase) {
            $normalized = str_replace($phrase, ' ', $normalized);
        }

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }

    public static function isGenericFollowUp(string $question): bool
    {
        $normalized = self::normalize($question);

        if ($normalized === '') {
            return false;
        }

        foreach ([
            'dagdagan ang detalye',
            'provide more details',
            'tell me a bit more',
            'tell me more about',
            'can you tell me more',
            'can you provide more',
            'pwede po bang dagdagan',
            'more about what you are feeling',
            'please provide more',
            'any additional details',
            'give me more details',
            'can you add more details',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function questionsAreEquivalent(string $current, string $previous): bool
    {
        $left = self::normalize($current);
        $right = self::normalize($previous);

        if ($left === '' || $right === '') {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        $leftTopic = AiConversationInterpreter::topicOf($current);
        $rightTopic = AiConversationInterpreter::topicOf($previous);

        if ($leftTopic !== null && $leftTopic === $rightTopic) {
            return true;
        }

        $shorter = mb_strlen($left) <= mb_strlen($right) ? $left : $right;
        $longer = $shorter === $left ? $right : $left;

        if (mb_strlen($shorter) >= 12 && str_contains($longer, $shorter)) {
            return true;
        }

        similar_text($left, $right, $percent);

        return $percent >= 86.0;
    }

    public static function firstQuestion(string $question): string
    {
        $question = trim($question);

        if ($question === '') {
            return $question;
        }

        if (substr_count($question, '?') <= 1) {
            return $question;
        }

        $cut = mb_strpos($question, '?');

        if (! is_int($cut)) {
            return $question;
        }

        return trim(mb_substr($question, 0, $cut + 1));
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s\']+/u', ' ', $text) ?? $text;
        $text = str_replace(["'", '’'], '', $text);

        return self::applyInformalSpelling(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    public static function applyInformalSpelling(string $normalized): string
    {
        $normalized = preg_replace('/\bbilga(ng|an)?\b/u', 'bigla$1', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bren\b/u', 'rin', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bkasaket\b/u', 'kasakit', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bsaket\b/u', 'sakit', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bganon\b/u', 'ganoon', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bngalay\b/u', 'nangangalay', $normalized) ?? $normalized;

        return $normalized;
    }

    public static function explicitLanguagePreference(string $text): ?string
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\b(please speak english|speak english|in english|mag english|mag-english)\b/u', $normalized)) {
            return 'english';
        }

        if (preg_match('/\b(magtagalog|in tagalog|speak tagalog|speak filipino|sa tagalog|sa filipino)\b/u', $normalized)) {
            return 'filipino';
        }

        return null;
    }

    public static function withoutLanguageInstruction(string $text): string
    {
        $stripped = preg_replace(
            '/\b(magtagalog(?: ka)?|please speak english|speak english(?: please)?|in english please|in tagalog please|tagalog please|sa tagalog(?: nga)?(?: po)?|sa filipino(?: nga)?(?: po)?)\b/iu',
            ' ',
            $text,
        ) ?? $text;

        $stripped = trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped);
        $stripped = trim($stripped, " \t\n\r\0\x0B.,;:!");

        return $stripped;
    }
}
