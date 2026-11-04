<?php

namespace App\Http\Requests\Admin;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\HospitalDataSource;
use App\Http\Requests\Admin\Concerns\ValidatesHospitalDataSource;
use App\Models\Clinic;
use App\Models\Department;
use App\Services\HospitalImport\RecordProvenanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateClinicRequest extends FormRequest
{
    use ValidatesHospitalDataSource;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $description = $this->input('description');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'description' => is_string($description) ? trim($description) : $description,
        ]);

        if ($this->input('description') === '') {
            $this->merge(['description' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clinic = $this->route('clinic');
        $clinicId = $clinic instanceof Clinic ? $clinic->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('clinics', 'name')
                    ->where(fn ($query) => $query->where('department_id', $this->input('department_id')))
                    ->ignore($clinicId),
            ],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(ClinicStatus::class)],
            ...$this->prohibitedProvenanceRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Clinic name is required.',
            'name.max' => 'Clinic name must be 255 characters or fewer.',
            'name.unique' => 'A clinic with this name already exists in the selected department.',
            'department_id.required' => 'Department is required.',
            'department_id.exists' => 'The selected department is invalid.',
            'description.max' => 'Description must be 2000 characters or fewer.',
            'status.required' => 'Status is required.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $status = ClinicStatus::from((string) $this->input('status'));
            $department = Department::query()->find($this->input('department_id'));
            $clinic = $this->route('clinic');

            if ($status === ClinicStatus::Active && $department?->status !== DepartmentStatus::Active) {
                $validator->errors()->add(
                    'status',
                    'An active clinic must belong to an active department.',
                );
            }

            if (! $clinic instanceof Clinic || ! $department instanceof Department) {
                return;
            }

            $source = $clinic->data_source instanceof HospitalDataSource
                ? $clinic->data_source
                : HospitalDataSource::Development;

            try {
                app(RecordProvenanceService::class)->assertCompatibleWithParent(
                    $department->data_source instanceof HospitalDataSource ? $department->data_source : null,
                    $source,
                    'department_id',
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
     * @return array{name: string, department_id: int, description: ?string, status: string}
     */
    public function clinicAttributes(): array
    {
        /** @var array{name: string, department_id: int, description: ?string, status: string} $validated */
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'department_id' => (int) $validated['department_id'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
        ];
    }
}
