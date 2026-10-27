<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PatientHeader;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the authenticated patient's read-only profile.
     */
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('patient');

        $patientRecord = $user->patient;
        $verified = $patientRecord?->integrityIsValid() ?? true;

        return view('patient.profile', [
            'patient' => PatientHeader::from($user),
            'active' => 'profile',
            'profile' => [
                'full_name' => $this->fullName($user),
                'date_of_birth' => $verified ? ($patientRecord?->date_of_birth?->format('F j, Y') ?: 'Not provided') : 'Unavailable',
                'sex' => $verified ? ($patientRecord?->sex?->label() ?: 'Not provided') : 'Unavailable',
                'contact_number' => $verified ? $this->displayValue($patientRecord?->contact_number) : 'Unavailable',
                'email' => $this->displayValue($user->email),
                'role' => 'Patient',
                'account_status' => $this->accountStatusLabel($user),
            ],
        ]);
    }

    private function fullName(User $user): string
    {
        $composed = collect([$user->first_name, $user->middle_name, $user->last_name])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' ');

        return $composed !== '' ? $composed : ($user->name ?: 'Not provided');
    }

    private function displayValue(?string $value): string
    {
        return filled($value) ? $value : 'Not provided';
    }

    private function accountStatusLabel(User $user): string
    {
        return match ($user->status?->value) {
            'active' => 'Active',
            'inactive' => 'Inactive',
            default => 'Not provided',
        };
    }
}
