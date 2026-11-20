<?php

namespace App\Http\Requests\Doctor;

use Illuminate\Foundation\Http\FormRequest;

class SaveConsultationDocumentationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'internal_notes' => ['nullable', 'string', 'max:10000'],
            'prescription' => ['nullable', 'string', 'max:5000'],
            'reminders' => ['nullable', 'string', 'max:5000'],
            'follow_up' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array{internal_notes: ?string, prescription: ?string, reminders: ?string, follow_up: ?string}
     */
    public function documentation(): array
    {
        return [
            'internal_notes' => $this->stringOrNull('internal_notes'),
            'prescription' => $this->stringOrNull('prescription'),
            'reminders' => $this->stringOrNull('reminders'),
            'follow_up' => $this->stringOrNull('follow_up'),
        ];
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
