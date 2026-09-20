<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPersonName implements ValidationRule
{
    /**
     * Letters, spaces, hyphens, and apostrophes only.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (preg_match("/^[\p{L}]+(?:[ '\-][\p{L}]+)*$/u", $value) === 1) {
            return;
        }

        $fail('The :attribute may only contain letters, spaces, hyphens, and apostrophes.');
    }
}
