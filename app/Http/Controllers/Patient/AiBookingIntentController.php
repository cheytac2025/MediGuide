<?php

namespace App\Http\Controllers\Patient;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\User;
use App\Support\AiBookingContext;
use App\Support\BookingIntent;
use App\Support\Demo\DemoBookingEnvironment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiBookingIntentController extends Controller
{
    public const string PATIENT_ACCOUNT_REQUIRED_MESSAGE = 'Appointment booking is only available for patient accounts. Please sign in with a patient account to continue.';

    public const string AUTH_REQUIRED_TO_BOOK_MESSAGE = 'Please sign in or create an account to continue booking your appointment.';

    public const string DEMONSTRATION_ACCOUNT_REQUIRED_MESSAGE = 'This demonstration appointment can only be completed by an authorized demonstration patient account. Approved hospital schedules remain available for ordinary patient booking when offered.';

    /**
     * Start booking from an AI doctor recommendation.
     *
     * Guests store a booking intent and are sent to login/register.
     * Authenticated patients go straight to the booking wizard when allowed.
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

        $doctor = Doctor::query()->with(['clinic.department', 'schedules'])->find($intent['doctor_id']);
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
            if (BookingIntent::isProvisionalOnlyFor($intent) && ! DemoBookingEnvironment::provisionalBookingPermitted($user)) {
                BookingIntent::forget($request->session());
                AiBookingContext::forget($request->session());

                return redirect()
                    ->route('ai-front-desk')
                    ->with('status', self::DEMONSTRATION_ACCOUNT_REQUIRED_MESSAGE);
            }

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

        return redirect()
            ->route('login')
            ->with('status', self::AUTH_REQUIRED_TO_BOOK_MESSAGE);
    }
}
