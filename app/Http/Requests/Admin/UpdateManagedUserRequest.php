<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use App\Rules\ValidPersonName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateManagedUserRequest extends FormRequest
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
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->id : null;

        return [
            'first_name' => ['required', 'string', 'max:100', new ValidPersonName],
            'middle_name' => ['nullable', 'string', 'max:100', new ValidPersonName],
            'last_name' => ['required', 'string', 'max:100', new ValidPersonName],
            'email' => ['required', 'string', 'email:filter', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'status' => ['required', Rule::enum(UserStatus::class)],
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
        ];
    }

    /**
     * @return array{first_name: string, middle_name: ?string, last_name: string, email: string, status: string}
     */
    public function accountAttributes(): array
    {
        return [
            'first_name' => (string) $this->validated('first_name'),
            'middle_name' => $this->validated('middle_name'),
            'last_name' => (string) $this->validated('last_name'),
            'email' => (string) $this->validated('email'),
            'status' => (string) $this->validated('status'),
        ];
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
