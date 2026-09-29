<?php

namespace App\Http\Requests\Staff;

use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDoctorScheduleRequest extends FormRequest
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
            'day_of_week' => ['required', Rule::enum(DayOfWeek::class)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'slot_duration' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(DoctorScheduleStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'day_of_week.required' => 'Day of week is required.',
            'start_time.required' => 'Start time is required.',
            'start_time.date_format' => 'Start time must be a valid time.',
            'end_time.required' => 'End time is required.',
            'end_time.date_format' => 'End time must be a valid time.',
            'slot_duration.required' => 'Slot duration is required.',
            'slot_duration.integer' => 'Slot duration must be a whole number of minutes.',
            'slot_duration.min' => 'Slot duration must be greater than zero.',
            'status.required' => 'Status is required.',
        ];
    }

    /**
     * @return array{day_of_week: string, start_time: string, end_time: string, slot_duration: int, status: string}
     */
    public function scheduleAttributes(): array
    {
        return [
            'day_of_week' => (string) $this->validated('day_of_week'),
            'start_time' => (string) $this->validated('start_time'),
            'end_time' => (string) $this->validated('end_time'),
            'slot_duration' => (int) $this->validated('slot_duration'),
            'status' => (string) $this->validated('status'),
        ];
    }
}
