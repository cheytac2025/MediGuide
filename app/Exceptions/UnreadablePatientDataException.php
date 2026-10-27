<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a protected column cannot be safely read.
 *
 * Messages never contain the stored value or its plaintext.
 */
class UnreadablePatientDataException extends RuntimeException
{
    public static function malformedCiphertext(): self
    {
        return new self('Protected patient data failed integrity verification or uses an unknown key.');
    }

    public static function plaintextRefused(): self
    {
        return new self('Protected patient data is stored as plaintext and legacy plaintext reads are disabled.');
    }
}
