<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\Auth\EmailOtpChallengeService;
use App\Support\AuthenticatedHome;
use App\Support\PostAuthBookingRedirect;
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

        $bookingRedirect = PostAuthBookingRedirect::afterAuthentication($request, $user instanceof User ? $user : null);

        if ($bookingRedirect !== null) {
            return $bookingRedirect;
        }

        return redirect()->intended(AuthenticatedHome::route($user));
    }
}
