<?php

namespace App\Http\Requests\Auth;

use App\Enums\Sex;
use App\Rules\ValidPersonName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => $this->normalizeName($this->input('first_name')),
            'middle_name' => $this->normalizeName($this->input('middle_name')),
            'last_name' => $this->normalizeName($this->input('last_name')),
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
            'contact_number' => $this->normalizeContactNumber($this->input('contact_number')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100', new ValidPersonName],
            'middle_name' => ['nullable', 'string', 'max:100', new ValidPersonName],
            'last_name' => ['required', 'string', 'max:100', new ValidPersonName],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'sex' => ['required', Rule::enum(Sex::class)],
            'contact_number' => ['required', 'string', 'regex:/^(09\d{9}|\+639\d{9})$/'],
            'email' => ['required', 'string', 'email:filter', 'max:255', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]+$/', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
            'date_of_birth.required' => 'Date of birth is required.',
            'date_of_birth.date' => 'Please enter a valid date of birth.',
            'date_of_birth.before_or_equal' => 'Date of birth cannot be in the future.',
            'sex.required' => 'Please select a sex.',
            'sex.enum' => 'Please select a valid sex option.',
            'contact_number.required' => 'Contact number is required.',
            'contact_number.regex' => 'Please enter a valid Philippine mobile number, such as 09XXXXXXXXX or +639XXXXXXXXX.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please enter a valid email address.',
            'email.regex' => 'Please enter a valid email address.',
            'email.unique' => 'An account with this email address already exists.',
            'password.required' => 'Password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'first name',
            'middle_name' => 'middle name',
            'last_name' => 'last name',
            'date_of_birth' => 'date of birth',
            'contact_number' => 'contact number',
            'email' => 'email address',
        ];
    }

    private function normalizeName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }

    private function normalizeContactNumber(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace('/\s+/', '', trim($value));
    }
}
