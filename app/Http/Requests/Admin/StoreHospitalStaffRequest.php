<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use App\Rules\ValidPersonName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreHospitalStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => $this->trimmed('first_name'),
            'middle_name' => $this->emptyToNull($this->trimmed('middle_name')),
            'last_name' => $this->trimmed('last_name'),
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
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
            'email' => ['required', 'string', 'email:filter', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->mixedCase()->letters()->numbers()->symbols(),
            ],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'departments' => ['required', 'array', 'min:1'],
            'departments.*' => ['integer', 'distinct', Rule::exists('departments', 'id')],
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
            'email.required' => 'Email address is required.',
            'email.unique' => 'An account with this email already exists.',
            'status.required' => 'Account status is required.',
            'departments.required' => 'Assign at least one department.',
            'departments.min' => 'Assign at least one department.',
            'departments.*.distinct' => 'Duplicate department assignments are not allowed.',
            'departments.*.exists' => 'Select a valid department.',
            'departments.*.integer' => 'Select a valid department.',
        ];
    }

    /**
     * @return array{first_name: string, middle_name: ?string, last_name: string, email: string, password: string, status: string}
     */
    public function accountAttributes(): array
    {
        return [
            'first_name' => (string) $this->validated('first_name'),
            'middle_name' => $this->validated('middle_name'),
            'last_name' => (string) $this->validated('last_name'),
            'email' => (string) $this->validated('email'),
            'password' => (string) $this->validated('password'),
            'status' => (string) $this->validated('status'),
        ];
    }

    /**
     * @return list<int>
     */
    public function departmentIds(): array
    {
        return array_map(intval(...), $this->validated('departments'));
    }

    private function trimmed(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }

    private function emptyToNull(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
