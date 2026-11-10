<?php

namespace App\Support\Appointments;

use Closure;

/**
 * Shared limits for patient-visible appointment messages.
 */
final class AppointmentMessageText
{
    public const int MAX_LENGTH = 500;

    /**
     * @return list<mixed>
     */
    public static function rules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:'.self::MAX_LENGTH,
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || $value === '') {
                    return;
                }

                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
                    $fail('The message contains characters that cannot be saved.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field, bool $required): array
    {
        $label = $field === 'note' ? 'note' : 'reason';

        return [
            "{$field}.required" => 'A patient-visible '.$label.' is required.',
            "{$field}.string" => 'The '.$label.' must be text.',
            "{$field}.max" => 'The '.$label.' must be '.self::MAX_LENGTH.' characters or fewer.',
        ];
    }

    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
