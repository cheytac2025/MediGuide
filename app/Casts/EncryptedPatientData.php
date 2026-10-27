<?php

namespace App\Casts;

use App\Support\PatientDataEncryption;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Encrypts a patient attribute at rest and decrypts it for authorized reads.
 *
 * Usage: EncryptedPatientData::class.':{string|date},{always|profile}'
 *
 * "profile" columns only write ciphertext once
 * config('patient_data.encrypt_profile_fields') is enabled, because their
 * legacy column types cannot hold ciphertext until widened. Reads always
 * accept both ciphertext and legacy plaintext (see PatientDataEncryption).
 *
 * @implements CastsAttributes<string|CarbonInterface|null, string|DateTimeInterface|null>
 */
final class EncryptedPatientData implements CastsAttributes
{
    private const ALWAYS = 'always';

    private const PROFILE = 'profile';

    public function __construct(
        private readonly string $type = 'string',
        private readonly string $activation = self::ALWAYS,
    ) {
        if (! in_array($this->type, ['string', 'date'], true)) {
            throw new InvalidArgumentException("Unsupported encrypted attribute type [{$this->type}].");
        }

        if (! in_array($this->activation, [self::ALWAYS, self::PROFILE], true)) {
            throw new InvalidArgumentException("Unsupported encryption activation [{$this->activation}].");
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): string|CarbonInterface|null
    {
        $plaintext = PatientDataEncryption::read($value === null ? null : (string) $value);

        if ($this->type === 'date') {
            return $plaintext === null || $plaintext === '' ? null : Date::parse($plaintext)->startOfDay();
        }

        return $plaintext;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $plaintext = $this->type === 'date'
            ? ($value instanceof DateTimeInterface ? Date::instance($value) : Date::parse((string) $value))->toDateString()
            : (string) $value;

        if ($plaintext === '' || ! $this->writesCiphertext()) {
            return $plaintext;
        }

        return PatientDataEncryption::encrypt($plaintext);
    }

    private function writesCiphertext(): bool
    {
        return $this->activation === self::ALWAYS
            || (bool) config('patient_data.encrypt_profile_fields');
    }
}
