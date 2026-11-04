<?php

namespace App\Http\Requests\Admin;

use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Http\Requests\Admin\Concerns\ValidatesHospitalDataSource;
use App\Models\Clinic;
use App\Rules\ValidPersonName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreDoctorRequest extends FormRequest
{
    use ValidatesHospitalDataSource;

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
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(DoctorStatus::class)],
            ...$this->prohibitedProvenanceRules(),
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
        ];
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $clinic = Clinic::query()->find($this->input('clinic_id'));

            $this->addIneligibleParentError(
                $validator,
                $clinic?->data_source instanceof HospitalDataSource ? $clinic->data_source : null,
                'clinic_id',
                'The selected clinic is not eligible for hospital records. Choose a hospital clinic, not a development-only clinic.',
            );
        });
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
     * @return array{first_name: string, middle_name: ?string, last_name: string, email: string, password: string}
     */
    public function accountAttributes(): array
    {
        return [
            'first_name' => (string) $this->validated('first_name'),
            'middle_name' => $this->validated('middle_name'),
            'last_name' => (string) $this->validated('last_name'),
            'email' => (string) $this->validated('email'),
            'password' => (string) $this->validated('password'),
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
