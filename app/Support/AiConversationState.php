<?php

namespace App\Support;

/**
 * Session-only routing memory for one AI front desk visit.
 *
 * This is not a medical record. It is not written to the database.
 * The rolling turn window stays bounded; the opening concern is pinned so
 * ordinary follow-up answers are not treated as a new, unrelated complaint.
 */
final readonly class AiConversationState
{
    public const int MAX_CLARIFICATION_ATTEMPTS = 4;

    public const int MAX_ASKED_QUESTIONS = 6;

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $turns
     * @param  list<string>  $askedQuestions
     */
    public function __construct(
        public array $turns,
        public ?string $openingConcern,
        public int $clarificationCount,
        public array $askedQuestions,
        public ?string $replyLanguage = null,
    ) {}

    public static function empty(): self
    {
        return new self([], null, 0, [], null);
    }

    /**
     * @param  array<int, mixed>  $turns
     */
    public static function fromTurns(array $turns): self
    {
        $context = AiConversationContext::fromTurns($turns);
        $opening = null;
        $questions = [];

        foreach ($turns as $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $role = $turn['role'] ?? null;
            $content = $turn['content'] ?? null;

            if (! is_string($content)) {
                continue;
            }

            $content = trim($content);

            if ($content === '') {
                continue;
            }

            if ($opening === null && $role === 'user' && AiVisitorReply::statesHealthConcern($content)) {
                $opening = self::bounded($content);
            }

            if ($role === 'assistant' && str_contains($content, '?')) {
                $questions[] = self::bounded($content);
            }
        }

        return new self(
            $context->turns,
            $opening,
            count($questions),
            self::tailQuestions($questions),
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public static function fromEnvelope(array $envelope): self
    {
        $turns = $envelope['turns'] ?? [];
        $context = AiConversationContext::fromTurns(is_array($turns) ? $turns : []);

        $opening = $envelope['opening_concern'] ?? null;
        $opening = is_string($opening) ? self::bounded($opening) : null;

        if ($opening === '') {
            $opening = null;
        }

        if (! AiVisitorReply::statesHealthConcern($opening ?? '')) {
            $opening = null;

            foreach ($context->turns as $turn) {
                if ($turn['role'] === 'user' && AiVisitorReply::statesHealthConcern($turn['content'])) {
                    $opening = $turn['content'];
                    break;
                }
            }
        }

        $count = $envelope['clarification_count'] ?? 0;
        $count = is_numeric($count) ? max(0, (int) $count) : 0;

        $asked = [];
        $storedQuestions = $envelope['asked_questions'] ?? [];

        if (is_array($storedQuestions)) {
            foreach ($storedQuestions as $question) {
                if (! is_string($question)) {
                    continue;
                }

                $question = self::bounded($question);

                if ($question !== '') {
                    $asked[] = $question;
                }
            }
        }

        $language = $envelope['reply_language'] ?? null;
        $language = is_string($language) && in_array($language, ['english', 'filipino', 'taglish'], true)
            ? $language
            : null;

        return new self(
            $context->turns,
            $opening,
            min($count, 20),
            self::tailQuestions($asked),
            $language,
        );
    }

    /**
     * @return array{
     *     v: int,
     *     opening_concern: ?string,
     *     clarification_count: int,
     *     asked_questions: list<string>,
     *     reply_language: ?string,
     *     turns: list<array{role: 'user'|'assistant', content: string}>
     * }
     */
    public function toEnvelope(): array
    {
        $facts = AiConversationInterpreter::interpret($this, $this->latestUserMessage());

        return [
            'v' => 2,
            'opening_concern' => $this->openingConcern,
            'clarification_count' => $this->clarificationCount,
            'asked_questions' => $this->askedQuestions,
            'reply_language' => $facts->replyLanguage,
            'turns' => $this->turns,
            'facts' => $facts->toArray(),
        ];
    }

    public function withExchange(string $visitorMessage, string $assistantMessage, bool $wasClarification): self
    {
        $turns = $this->turns;
        $turns[] = [
            'role' => 'user',
            'content' => $visitorMessage,
        ];
        $turns[] = [
            'role' => 'assistant',
            'content' => $assistantMessage,
        ];

        $opening = AiVisitorReply::statesHealthConcern($this->openingConcern ?? '')
            ? $this->openingConcern
            : (AiVisitorReply::statesHealthConcern($visitorMessage) ? self::bounded($visitorMessage) : null);
        $count = $this->clarificationCount;
        $asked = $this->askedQuestions;

        if ($wasClarification) {
            $count++;
            $question = self::bounded($assistantMessage);

            if ($question !== '') {
                $asked[] = $question;
            }
        }

        $provisional = new self(
            AiConversationContext::fromTurns($turns)->turns,
            $opening === '' ? null : $opening,
            $count,
            self::tailQuestions($asked),
            $this->replyLanguage,
        );

        return new self(
            $provisional->turns,
            $provisional->openingConcern,
            $provisional->clarificationCount,
            $provisional->askedQuestions,
            AiConversationInterpreter::interpret($provisional, $visitorMessage)->replyLanguage,
        );
    }

    public function reachedClarificationLimit(): bool
    {
        return $this->clarificationCount >= self::MAX_CLARIFICATION_ATTEMPTS;
    }

    public function latestUserMessage(): string
    {
        for ($index = count($this->turns) - 1; $index >= 0; $index--) {
            if ($this->turns[$index]['role'] === 'user') {
                return $this->turns[$index]['content'];
            }
        }

        return $this->openingConcern ?? '';
    }

    /**
     * @param  list<string>  $questions
     * @return list<string>
     */
    private static function tailQuestions(array $questions): array
    {
        if (count($questions) <= self::MAX_ASKED_QUESTIONS) {
            return array_values($questions);
        }

        return array_values(array_slice($questions, -self::MAX_ASKED_QUESTIONS));
    }

    private static function bounded(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) > AiConversationContext::MAX_CHARACTERS) {
            return mb_substr($text, 0, AiConversationContext::MAX_CHARACTERS);
        }

        return $text;
    }
}
