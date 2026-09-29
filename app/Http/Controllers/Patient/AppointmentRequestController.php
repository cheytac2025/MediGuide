<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppointmentRequestController extends Controller
{
    /**
     * Minimal destination after a booking request.
     * The full My Appointments list is a later milestone.
     */
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('patient.appointments', [
            'patient' => [
                'name' => $user->name,
                'role' => 'Patient',
                'initials' => $user->initials(),
                'unread_notifications' => 0,
            ],
            'active' => 'appointments',
            'submitted' => session('appointment_submitted'),
        ]);
    }
}
