<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A protected record failed integrity verification and was not changed.
 *
 * The message is safe to show: it contains no field values and no key material.
 */
class RecordIntegrityException extends RuntimeException
{
    public const MESSAGE = 'This record could not be verified and was not changed.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
