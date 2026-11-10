<?php

namespace App\Http\Requests\Staff;

use App\Models\User;
use App\Services\HospitalStaffScope;
use App\Support\Appointments\AppointmentMessageText;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRescheduleRequest extends FormRequest
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
            'appointment_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'doctor_schedule_id' => ['required', 'integer'],
            'reason' => AppointmentMessageText::rules(true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'appointment_date.required' => 'Choose a date.',
            'appointment_date.date_format' => 'Choose a valid date.',
            'start_time.required' => 'Choose a time.',
            'start_time.date_format' => 'Choose a valid time.',
            'doctor_schedule_id.required' => 'Choose an available time.',
            ...AppointmentMessageText::messages('reason', true),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => AppointmentMessageText::clean(is_string($this->input('reason')) ? $this->input('reason') : null),
        ]);
    }
}
