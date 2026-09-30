<?php

namespace App\Http\Requests\Staff;

use App\Enums\DoctorScheduleStatus;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\HospitalStaffScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDoctorScheduleStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $schedule = $this->route('doctorSchedule');

        if (! $user instanceof User || ! $schedule instanceof DoctorSchedule) {
            return false;
        }

        app(HospitalStaffScope::class)->ensureSchedule($user, $schedule);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(DoctorScheduleStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status is required.',
        ];
    }
}
