<?php

namespace App\Http\Requests\Staff;

use App\Models\User;
use App\Services\HospitalStaffScope;
use App\Support\Appointments\AppointmentMessageText;
use Illuminate\Foundation\Http\FormRequest;

class RejectAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $appointmentId = $this->route('appointment');

        if (! $user instanceof User || ! is_numeric($appointmentId)) {
            return false;
        }

        app(HospitalStaffScope::class)->appointment($user, (int) $appointmentId);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => AppointmentMessageText::rules(true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AppointmentMessageText::messages('reason', true);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => AppointmentMessageText::clean(is_string($this->input('reason')) ? $this->input('reason') : null),
        ]);
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
