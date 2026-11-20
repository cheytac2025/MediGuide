<?php

namespace App\Http\Responses;

use App\Enums\RoleName;
use App\Http\Controllers\Patient\AiBookingIntentController;
use App\Models\User;
use App\Support\AiBookingContext;
use App\Support\AiDisclaimer;
use App\Support\AuthenticatedHome;
use App\Support\BookingIntent;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasRole(RoleName::Patient)) {
            $intent = BookingIntent::consumeValidated($request->session());

            if ($intent !== null) {
                return redirect()->route('patient.book-appointment', $intent);
            }
        } elseif (BookingIntent::get($request->session()) !== null) {
            BookingIntent::forget($request->session());
            AiBookingContext::forget($request->session());

            $target = AiDisclaimer::isAcknowledged($request->session())
                ? redirect()->route('ai-front-desk')
                : redirect()->route('ai-disclaimer');

            return $target->with('status', AiBookingIntentController::PATIENT_ACCOUNT_REQUIRED_MESSAGE);
        } else {
            BookingIntent::forget($request->session());
        }

        return redirect()->intended(AuthenticatedHome::route($user));
    }
}
