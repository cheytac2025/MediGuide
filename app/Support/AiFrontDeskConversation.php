<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Bounded AI front desk turns kept in the Laravel session.
 *
 * This is not a medical record and is not written to the database.
 * Disclaimer acknowledgement and booking intent use different session keys.
 *
 * Stored payloads may be a legacy turn list or a v1 envelope that pins the
 * opening concern without expanding unlimited chat history.
 */
final class AiFrontDeskConversation
{
    public const string SESSION_KEY = 'ai_front_desk_conversation';

    /**
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public static function turns(Session $session): array
    {
        return self::state($session)->turns;
    }

    public static function state(Session $session): AiConversationState
    {
        $stored = $session->get(self::SESSION_KEY);

        if (! is_array($stored)) {
            return AiConversationState::empty();
        }

        if (self::isEnvelope($stored)) {
            return AiConversationState::fromEnvelope($stored);
        }

        return AiConversationState::fromTurns($stored);
    }

    public static function remember(
        Session $session,
        string $visitorMessage,
        string $assistantMessage,
        bool $wasClarification = false,
    ): void {
        $next = self::state($session)->withExchange(
            $visitorMessage,
            $assistantMessage,
            $wasClarification,
        );

        $session->put(self::SESSION_KEY, $next->toEnvelope());
    }

    public static function clear(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /**
     * @param  array<mixed>  $stored
     */
    private static function isEnvelope(array $stored): bool
    {
        if (array_is_list($stored)) {
            return false;
        }

        return array_key_exists('turns', $stored)
            || array_key_exists('opening_concern', $stored)
            || array_key_exists('v', $stored);
    }
}
