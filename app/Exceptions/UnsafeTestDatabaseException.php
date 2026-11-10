<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when the PHPUnit process is not explicitly confined to SQLite :memory:.
 *
 * The message names the rejected setting and does not include configured values.
 */
final class UnsafeTestDatabaseException extends RuntimeException
{
    public static function unsafe(string $reason): self
    {
        return new self(
            'Unsafe test database configuration was rejected before a database connection. '.$reason
        );
    }
}
