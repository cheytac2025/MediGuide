<?php

namespace App\Rules;

use App\Models\Department;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueDepartmentName implements ValidationRule
{
    public function __construct(private ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $exists = Department::query()
            ->when(
                $this->ignoreId !== null,
                fn ($query) => $query->whereKeyNot($this->ignoreId),
            )
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
            ->exists();

        if ($exists) {
            $fail('A department with this name already exists.');
        }
    }
}
