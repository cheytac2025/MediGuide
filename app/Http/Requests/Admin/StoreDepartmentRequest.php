<?php

namespace App\Http\Requests\Admin;

use App\Enums\DepartmentStatus;
use App\Http\Requests\Admin\Concerns\ValidatesHospitalDataSource;
use App\Rules\UniqueDepartmentName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', new UniqueDepartmentName],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(DepartmentStatus::class)],
            ...$this->prohibitedProvenanceRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Department name is required.',
            'name.max' => 'Department name must be 255 characters or fewer.',
            'description.max' => 'Description must be 2000 characters or fewer.',
            'status.required' => 'Status is required.',
        ];
    }

    /**
     * @return array{name: string, description: ?string, status: string, data_source: string}
     */
    public function departmentAttributes(): array
    {
        /** @var array{name: string, description: ?string, status: string} $validated */
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'data_source' => $this->hospitalProvidedSource()->value,
        ];
    }
}
