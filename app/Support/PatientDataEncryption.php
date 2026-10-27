<?php

namespace App\Support;

use App\Exceptions\UnreadablePatientDataException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Application-level encryption for protected patient columns.
 *
 * Uses Laravel's encrypter (config app.cipher, AES-256-CBC with a random IV
 * and HMAC-SHA256 over the IV and ciphertext). A stored value is classified
 * by full payload parsing and MAC verification, never by a string prefix:
 *
 * - encrypted: a Laravel payload that verifies and decrypts with the current
 *   or a previous application key
 * - malformed: shaped like a Laravel payload but fails verification
 *   (tampered, truncated, or encrypted with an unknown key)
 * - plaintext: not a Laravel payload at all (legacy record)
 * - empty: null or ''
 */
final class PatientDataEncryption
{
    public const EMPTY = 'empty';

    public const ENCRYPTED = 'encrypted';

    public const PLAINTEXT = 'plaintext';

    public const MALFORMED = 'malformed';

    public static function encrypt(string $plaintext): string
    {
        return Crypt::encryptString($plaintext);
    }

    /**
     * @return self::EMPTY|self::ENCRYPTED|self::PLAINTEXT|self::MALFORMED
     */
    public static function classify(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return self::EMPTY;
        }

        try {
            Crypt::decryptString($stored);

            return self::ENCRYPTED;
        } catch (DecryptException) {
            return self::looksLikePayload($stored) ? self::MALFORMED : self::PLAINTEXT;
        }
    }

    /**
     * Return the plaintext for a stored value, honouring the legacy transition setting.
     */
    public static function read(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            if (self::looksLikePayload($stored)) {
                throw UnreadablePatientDataException::malformedCiphertext();
            }
        }

        if (! config('patient_data.allow_legacy_plaintext')) {
            throw UnreadablePatientDataException::plaintextRefused();
        }

        return $stored;
    }

    /**
     * Structural check mirroring Laravel's encrypter payload format:
     * base64(JSON{iv, value, mac, tag}).
     */
    private static function looksLikePayload(string $stored): bool
    {
        $json = base64_decode($stored, true);

        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            return false;
        }

        foreach (['iv', 'value', 'mac'] as $key) {
            if (! array_key_exists($key, $payload) || ! is_string($payload[$key])) {
                return false;
            }
        }

        return true;
    }
}
