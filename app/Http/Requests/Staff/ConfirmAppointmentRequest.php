<?php

namespace App\Http\Requests\Staff;

use App\Models\User;
use App\Services\HospitalStaffScope;
use App\Support\Appointments\AppointmentMessageText;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->appointmentIsInScope();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => AppointmentMessageText::rules(false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AppointmentMessageText::messages('note', false);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('note')) {
            return;
        }

        $this->merge([
            'note' => AppointmentMessageText::clean($this->input('note') === null ? null : (string) $this->input('note')),
        ]);
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }

    private function appointmentIsInScope(): bool
    {
        $user = $this->user();
        $appointmentId = $this->route('appointment');

        if (! $user instanceof User || ! is_numeric($appointmentId)) {
            return false;
        }

        app(HospitalStaffScope::class)->appointment($user, (int) $appointmentId);

        return true;
    }
}
