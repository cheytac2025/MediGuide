<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Bounded AI front desk turns kept in the Laravel session.
 *
 * This is not a medical record and is not written to the database.
 * Disclaimer acknowledgement and booking intent use different session keys.
 */
final class AiFrontDeskConversation
{
    public const string SESSION_KEY = 'ai_front_desk_conversation';

    /**
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public static function turns(Session $session): array
    {
        $stored = $session->get(self::SESSION_KEY);

        return AiConversationContext::fromTurns(is_array($stored) ? $stored : [])->turns;
    }

    public static function remember(Session $session, string $visitorMessage, string $assistantMessage): void
    {
        $turns = self::turns($session);
        $turns[] = [
            'role' => 'user',
            'content' => $visitorMessage,
        ];
        $turns[] = [
            'role' => 'assistant',
            'content' => $assistantMessage,
        ];

        $session->put(self::SESSION_KEY, AiConversationContext::fromTurns($turns)->turns);
    }

    public static function clear(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
