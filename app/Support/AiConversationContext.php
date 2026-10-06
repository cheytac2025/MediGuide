<?php

namespace App\Support;

/**
 * Recent visitor/assistant turns supplied to one guidance request.
 *
 * This is not stored. Callers pass only the turns they still have in the
 * current interaction, and this object keeps a small tail of them.
 */
final readonly class AiConversationContext
{
    public const int MAX_TURNS = 6;

    public const int MAX_CHARACTERS = 1000;

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $turns
     */
    private function __construct(public array $turns) {}

    /**
     * @param  array<int, mixed>  $turns
     */
    public static function fromTurns(array $turns): self
    {
        $normalized = [];

        foreach ($turns as $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $role = $turn['role'] ?? null;
            $content = $turn['content'] ?? null;

            if (($role !== 'user' && $role !== 'assistant') || ! is_string($content)) {
                continue;
            }

            $content = trim($content);

            if ($content === '') {
                continue;
            }

            if (mb_strlen($content) > self::MAX_CHARACTERS) {
                $content = mb_substr($content, 0, self::MAX_CHARACTERS);
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        if (count($normalized) > self::MAX_TURNS) {
            $normalized = array_slice($normalized, -self::MAX_TURNS);
        }

        return new self($normalized);
    }

    /**
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function messagesEndingWith(string $concern): array
    {
        $messages = $this->turns;
        $messages[] = [
            'role' => 'user',
            'content' => $concern,
        ];

        return $messages;
    }
}
