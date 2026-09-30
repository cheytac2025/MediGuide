<?php

namespace App\Http\Requests\Admin;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Models\Clinic;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateClinicRequest extends FormRequest
{
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
        });
    }

    /**
     * @return array{name: string, department_id: int, description: ?string, status: string}
     */
    public function clinicAttributes(): array
    {
        /** @var array{name: string, department_id: int, description: ?string, status: string} $validated */
        $validated = $this->validated();

        return $validated;
    }
}
