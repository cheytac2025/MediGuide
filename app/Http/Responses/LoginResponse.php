<?php

namespace App\Http\Responses;

use App\Enums\RoleName;
use App\Http\Controllers\Patient\AiBookingIntentController;
use App\Models\User;
use App\Services\Auth\EmailOtpChallengeService;
use App\Support\AiBookingContext;
use App\Support\AiDisclaimer;
use App\Support\AuthenticatedHome;
use App\Support\BookingIntent;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LoginResponse implements LoginResponseContract
{
    public function __construct(private readonly EmailOtpChallengeService $emailOtp) {}

    public function toResponse($request): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->emailOtp->userRequiresChallenge($user)) {
            if ($request->session()->get(EmailOtpChallengeService::SESSION_PASSED) === true
                && (int) $request->session()->get(EmailOtpChallengeService::SESSION_USER) === (int) $user->id) {
                // Already satisfied in this flow.
            } else {
                try {
                    $started = $this->emailOtp->begin($user);
                } catch (Throwable) {
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()
                        ->route('login')
                        ->withErrors([
                            'email' => 'We could not send a sign-in code. Please try again later.',
                        ]);
                }

                $request->session()->put(EmailOtpChallengeService::SESSION_CHALLENGE, $started['challenge_id']);
                $request->session()->put(EmailOtpChallengeService::SESSION_USER, $user->id);
                $request->session()->forget(EmailOtpChallengeService::SESSION_PASSED);

                return redirect()->route('auth.email-otp.challenge');
            }
        }

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
