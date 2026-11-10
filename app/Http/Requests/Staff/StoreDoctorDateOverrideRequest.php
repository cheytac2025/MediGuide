<?php

namespace App\Http\Requests\Staff;

use App\Models\Doctor;
use App\Models\User;
use App\Services\HospitalStaffScope;
use Illuminate\Foundation\Http\FormRequest;

class StoreDoctorDateOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $doctor = $this->route('doctor');

        if (! $user instanceof User || ! $doctor instanceof Doctor) {
            return false;
        }

        app(HospitalStaffScope::class)->ensureDoctor($user, $doctor);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'unavailable' => ['boolean'],
            'windows' => ['array'],
            'windows.*.start_time' => ['nullable', 'date_format:H:i'],
            'windows.*.end_time' => ['nullable', 'date_format:H:i'],
            'windows.*.slot_duration' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Choose a date.',
            'date.date_format' => 'Choose a valid date.',
            'windows.*.start_time.date_format' => 'Start time must be a valid time.',
            'windows.*.end_time.date_format' => 'End time must be a valid time.',
            'windows.*.slot_duration.integer' => 'Slot duration must be a whole number of minutes.',
            'windows.*.slot_duration.min' => 'Slot duration must be greater than zero.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $windows = [];

        foreach ((array) $this->input('windows', []) as $window) {
            if (! is_array($window)) {
                continue;
            }

            $start = trim((string) ($window['start_time'] ?? ''));
            $end = trim((string) ($window['end_time'] ?? ''));
            $duration = $window['slot_duration'] ?? null;

            if ($start === '' && $end === '') {
                continue;
            }

            $windows[] = [
                'start_time' => $start,
                'end_time' => $end,
                'slot_duration' => $duration,
            ];
        }

        $this->merge([
            'unavailable' => filter_var($this->input('unavailable'), FILTER_VALIDATE_BOOLEAN),
            'windows' => $windows,
        ]);
    }

    /**
     * @return list<array{start_time: string, end_time: string, slot_duration: int}>
     */
    public function windows(): array
    {
        $windows = [];

        foreach ((array) $this->validated('windows', []) as $window) {
            if (! is_array($window)) {
                continue;
            }

            $windows[] = [
                'start_time' => (string) ($window['start_time'] ?? ''),
                'end_time' => (string) ($window['end_time'] ?? ''),
                'slot_duration' => (int) ($window['slot_duration'] ?? 0),
            ];
        }

        return $windows;
    }
}
