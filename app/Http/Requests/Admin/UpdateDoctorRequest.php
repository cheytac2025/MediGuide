<?php

namespace App\Http\Requests\Admin;

use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Http\Requests\Admin\Concerns\ValidatesHospitalDataSource;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Rules\ValidPersonName;
use App\Services\HospitalImport\RecordProvenanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateDoctorRequest extends FormRequest
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
        $doctor = $this->route('doctor');
        $userId = $doctor instanceof Doctor ? $doctor->user_id : null;

        $rules = [
            'display_name' => ['required', 'string', 'max:255'],
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(DoctorStatus::class)],
            ...$this->prohibitedProvenanceRules(),
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $doctor = $this->route('doctor');
            $clinic = Clinic::query()->find($this->input('clinic_id'));

            if (! $doctor instanceof Doctor || ! $clinic instanceof Clinic) {
                return;
            }

            $source = $doctor->data_source instanceof HospitalDataSource
                ? $doctor->data_source
                : HospitalDataSource::Development;

            try {
                app(RecordProvenanceService::class)->assertCompatibleWithParent(
                    $clinic->data_source instanceof HospitalDataSource ? $clinic->data_source : null,
                    $source,
                    'clinic_id',
                );
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }

    /**
     * @return array{display_name: string, clinic_id: int, specialization: ?string, status: string}
     */
    public function doctorAttributes(): array
    {
        $validated = $this->validated();

        return [
            'display_name' => (string) $validated['display_name'],
            'clinic_id' => (int) $validated['clinic_id'],
            'specialization' => $validated['specialization'] ?? null,
            'status' => (string) $validated['status'],
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
