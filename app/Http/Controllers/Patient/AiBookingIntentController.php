<?php

namespace App\Http\Controllers\Patient;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuthenticatedHome;
use App\Support\BookingIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiBookingIntentController extends Controller
{
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
            return redirect()
                ->route('ai-front-desk')
                ->with('status', 'That doctor is not available for booking right now.');
        }

        /** @var User|null $user */
        $user = $request->user();

        if ($user instanceof User && $user->hasRole(RoleName::Patient)) {
            BookingIntent::forget($request->session());

            return redirect()->route('patient.book-appointment', $intent);
        }

        if ($user instanceof User) {
            BookingIntent::forget($request->session());

            return redirect()->to(AuthenticatedHome::route($user));
        }

        BookingIntent::store(
            $request->session(),
            $intent['clinic_id'],
            $intent['doctor_id'],
        );

        return redirect()->route('login');
    }
}
