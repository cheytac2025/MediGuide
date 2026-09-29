<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;

final class AiDisclaimer
{
    public const SESSION_KEY = 'ai_disclaimer_acknowledged';

    public static function isAcknowledged(Session $session): bool
    {
        return $session->get(self::SESSION_KEY) === true;
    }

    public static function acknowledge(Session $session): void
    {
        $session->put(self::SESSION_KEY, true);
    }
}
