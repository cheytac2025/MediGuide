<?php

namespace App\Http\Requests\Doctor;

use Illuminate\Foundation\Http\FormRequest;

class PublishPatientInstructionsRequest extends FormRequest
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
            'prescription' => ['nullable', 'string', 'max:5000'],
            'reminders' => ['nullable', 'string', 'max:5000'],
            'follow_up' => ['nullable', 'string', 'max:5000'],
            'revision_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{prescription: ?string, reminders: ?string, follow_up: ?string, revision_reason: ?string}
     */
    public function instructions(): array
    {
        return [
            'prescription' => $this->stringOrNull('prescription'),
            'reminders' => $this->stringOrNull('reminders'),
            'follow_up' => $this->stringOrNull('follow_up'),
            'revision_reason' => $this->stringOrNull('revision_reason'),
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
