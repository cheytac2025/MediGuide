<?php

namespace App\Http\Requests\Admin;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\HospitalDataSource;
use App\Http\Requests\Admin\Concerns\ValidatesHospitalDataSource;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClinicRequest extends FormRequest
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
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('clinics', 'name')->where(
                    fn ($query) => $query->where('department_id', $this->input('department_id')),
                ),
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

            if ($status === ClinicStatus::Active && $department?->status !== DepartmentStatus::Active) {
                $validator->errors()->add(
                    'status',
                    'An active clinic must belong to an active department.',
                );
            }

            $this->addIneligibleParentError(
                $validator,
                $department?->data_source instanceof HospitalDataSource ? $department->data_source : null,
                'department_id',
                'The selected department is not eligible for hospital records. Choose a hospital department, not a development-only department.',
            );
        });
    }

    /**
     * @return array{name: string, department_id: int, description: ?string, status: string, data_source: string}
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
            'data_source' => $this->hospitalProvidedSource()->value,
        ];
    }
}
