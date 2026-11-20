<?php

namespace App\Http\Controllers\Patient;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\User;
use App\Support\AiBookingContext;
use App\Support\BookingIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiBookingIntentController extends Controller
{
    public const string PATIENT_ACCOUNT_REQUIRED_MESSAGE = 'Appointment booking is only available for patient accounts. Please sign in with a patient account to continue.';

    /**
     * Start booking from an AI doctor recommendation.
     *
     * Guests store a booking intent and are sent to login.
     * Authenticated patients go straight to the booking wizard.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'clinic_id' => ['required', 'integer'],
            'doctor_id' => ['required', 'integer'],
        ]);

        $intent = BookingIntent::validated([
            'clinic_id' => $validated['clinic_id'],
            'doctor_id' => $validated['doctor_id'],
        ]);

        if ($intent === null) {
            AiBookingContext::forget($request->session());

            return redirect()
                ->route('ai-front-desk')
                ->with('status', 'That doctor is not available for booking right now.');
        }

        $doctor = Doctor::query()->find($intent['doctor_id']);
        $specialization = is_string($doctor?->specialization) ? trim($doctor->specialization) : null;
        if ($specialization === '') {
            $specialization = null;
        }

        AiBookingContext::store(
            $request->session(),
            $intent['clinic_id'],
            $intent['doctor_id'],
            $specialization,
        );

        /** @var User|null $user */
        $user = $request->user();

        if ($user instanceof User && $user->hasRole(RoleName::Patient)) {
            BookingIntent::forget($request->session());

            return redirect()->route('patient.book-appointment', $intent);
        }

        if ($user instanceof User) {
            BookingIntent::forget($request->session());
            AiBookingContext::forget($request->session());

            return redirect()
                ->route('ai-front-desk')
                ->with('status', self::PATIENT_ACCOUNT_REQUIRED_MESSAGE);
        }

        BookingIntent::store(
            $request->session(),
            $intent['clinic_id'],
            $intent['doctor_id'],
        );

        return redirect()->route('login');
    }
}
