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

    public static function negativeConfirmation(string $text): bool
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, [
            'no',
            'nope',
            'none',
            'hindi',
            'hindi po',
            'hinde',
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
        $normalized = self::normalize($text);

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
        $normalized = self::normalize($text);

        if ($normalized === '') {
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

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
