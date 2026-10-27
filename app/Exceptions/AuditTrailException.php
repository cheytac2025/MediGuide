<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A required audit event could not be written, so the business change was not kept.
 *
 * The message is safe to show. It contains no event context and no key material.
 */
class AuditTrailException extends RuntimeException
{
    public const MESSAGE = 'This action could not be recorded and was not completed.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
