<?php

namespace App\Http\Requests\Admin;

use App\Enums\DoctorStatus;
use App\Models\Doctor;
use App\Rules\ValidPersonName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'display_name' => $this->trimmed('display_name'),
            'specialization' => $this->emptyToNull($this->trimmed('specialization')),
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
        $doctor = $this->route('doctor');
        $userId = $doctor instanceof Doctor ? $doctor->user_id : null;

        $rules = [
            'display_name' => ['required', 'string', 'max:255'],
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(DoctorStatus::class)],
        ];

        if ($userId !== null) {
            $rules['first_name'] = ['required', 'string', 'max:100', new ValidPersonName];
            $rules['middle_name'] = ['nullable', 'string', 'max:100', new ValidPersonName];
            $rules['last_name'] = ['required', 'string', 'max:100', new ValidPersonName];
            $rules['email'] = ['required', 'string', 'email:filter', 'max:255', Rule::unique('users', 'email')->ignore($userId)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.required' => 'Display name is required.',
            'clinic_id.required' => 'Clinic is required.',
            'clinic_id.exists' => 'The selected clinic is invalid.',
            'email.unique' => 'An account with this email already exists.',
            'email.required' => 'Email address is required.',
        ];
    }

    /**
     * @return array{display_name: string, clinic_id: int, specialization: ?string, status: string}
     */
    public function doctorAttributes(): array
    {
        return [
            'display_name' => (string) $this->validated('display_name'),
            'clinic_id' => (int) $this->validated('clinic_id'),
            'specialization' => $this->validated('specialization'),
            'status' => (string) $this->validated('status'),
        ];
    }

    /**
     * @return array{first_name: string, middle_name: ?string, last_name: string, email: string}|null
     */
    public function accountAttributes(): ?array
    {
        $doctor = $this->route('doctor');

        if (! $doctor instanceof Doctor || $doctor->user_id === null) {
            return null;
        }

        return [
            'first_name' => (string) $this->validated('first_name'),
            'middle_name' => $this->validated('middle_name'),
            'last_name' => (string) $this->validated('last_name'),
            'email' => (string) $this->validated('email'),
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
