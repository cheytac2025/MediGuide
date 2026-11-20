<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\EmailOtpChallengeService;
use App\Support\AuthenticatedHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class EmailOtpChallengeController extends Controller
{
    public function __construct(private readonly EmailOtpChallengeService $otp) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (! $this->otp->featureEnabled()) {
            return redirect()->route('login');
        }

        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return view('auth.email-otp-challenge', [
            'emailHint' => $this->maskEmail($user->email),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);

        $user = $this->pendingUser($request);
        $challengeId = (string) $request->session()->get(EmailOtpChallengeService::SESSION_CHALLENGE, '');

        if ($user === null || $challengeId === '') {
            return redirect()->route('login');
        }

        $result = $this->otp->verify($user, $challengeId, (string) $request->input('code'));

        if ($result !== 'ok') {
            $messages = [
                'invalid' => 'That code is incorrect.',
                'expired' => 'That code has expired. Request a new one.',
                'locked' => 'Too many attempts. Sign in again to request a new code.',
                'consumed' => 'That code was already used. Sign in again.',
            ];

            if (in_array($result, ['locked', 'consumed', 'expired'], true)) {
                $this->clearChallenge($request);
                Auth::logout();

                return redirect()->route('login')->withErrors([
                    'email' => $messages[$result],
                ]);
            }

            return back()->withErrors(['code' => $messages[$result] ?? 'Verification failed.']);
        }

        $request->session()->put(EmailOtpChallengeService::SESSION_PASSED, true);
        $request->session()->forget([
            EmailOtpChallengeService::SESSION_CHALLENGE,
            EmailOtpChallengeService::SESSION_USER,
        ]);
        $request->session()->regenerate();

        return redirect()->intended(AuthenticatedHome::route($user));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        $challengeId = (string) $request->session()->get(EmailOtpChallengeService::SESSION_CHALLENGE, '');

        if ($user === null || $challengeId === '') {
            return redirect()->route('login');
        }

        try {
            $ok = $this->otp->resend($user, $challengeId);
        } catch (\Throwable) {
            return back()->withErrors([
                'code' => 'We could not send a new code. Try again later.',
            ]);
        }

        if (! $ok) {
            return back()->withErrors([
                'code' => 'Please wait before requesting another code.',
            ]);
        }

        return back()->with('status', 'A new sign-in code was sent.');
    }

    private function pendingUser(Request $request): ?User
    {
        $userId = $request->session()->get(EmailOtpChallengeService::SESSION_USER);

        if (! is_numeric($userId)) {
            return null;
        }

        $user = User::query()->find((int) $userId);

        return $user instanceof User ? $user : null;
    }

    private function clearChallenge(Request $request): void
    {
        $request->session()->forget([
            EmailOtpChallengeService::SESSION_CHALLENGE,
            EmailOtpChallengeService::SESSION_USER,
            EmailOtpChallengeService::SESSION_PASSED,
        ]);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($local === '' || $domain === '') {
            return 'your email';
        }

        $visible = mb_substr($local, 0, 1);

        return $visible.'***@'.$domain;
    }
}
