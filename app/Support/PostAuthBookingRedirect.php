<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Http\Controllers\Patient\AiBookingIntentController;
use App\Models\User;
use App\Support\Demo\DemoBookingEnvironment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Resumes AI Front Desk booking after login, registration-linked login,
 * email verification, or email OTP success.
 *
 * Re-validates clinic/doctor identifiers before redirecting. Does not
 * invent schedules or bypass provisional booking restrictions.
 */
final class PostAuthBookingRedirect
{
    public static function afterAuthentication(Request $request, ?User $user): ?RedirectResponse
    {
        if (! $user instanceof User) {
            BookingIntent::forget($request->session());

            return null;
        }

        if ($user->hasRole(RoleName::Patient)) {
            $intent = BookingIntent::consumeValidated($request->session());

            if ($intent !== null) {
                return self::redirectPatientToBooking($request, $user, $intent);
            }

            $context = AiBookingContext::validated(AiBookingContext::get($request->session()));

            if ($context !== null) {
                $intent = BookingIntent::validated([
                    'clinic_id' => $context['clinic_id'],
                    'doctor_id' => $context['doctor_id'],
                ]);

                if ($intent !== null) {
                    return self::redirectPatientToBooking($request, $user, $intent);
                }
            }

            return null;
        }

        if (BookingIntent::get($request->session()) !== null) {
            BookingIntent::forget($request->session());
            AiBookingContext::forget($request->session());

            $target = AiDisclaimer::isAcknowledged($request->session())
                ? redirect()->route('ai-front-desk')
                : redirect()->route('ai-disclaimer');

            return $target->with('status', AiBookingIntentController::PATIENT_ACCOUNT_REQUIRED_MESSAGE);
        }

        BookingIntent::forget($request->session());

        return null;
    }

    /**
     * @param  array{clinic_id: int, doctor_id: int}  $intent
     */
    private static function redirectPatientToBooking(Request $request, User $user, array $intent): RedirectResponse
    {
        if (BookingIntent::isProvisionalOnlyFor($intent) && ! DemoBookingEnvironment::provisionalBookingPermitted($user)) {
            AiBookingContext::forget($request->session());

            $target = AiDisclaimer::isAcknowledged($request->session())
                ? redirect()->route('ai-front-desk')
                : redirect()->route('ai-disclaimer');

            return $target->with('status', AiBookingIntentController::DEMONSTRATION_ACCOUNT_REQUIRED_MESSAGE);
        }

        return redirect()->route('patient.book-appointment', $intent);
    }
}
