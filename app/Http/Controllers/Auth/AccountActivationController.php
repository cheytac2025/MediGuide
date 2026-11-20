<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountActivationController extends Controller
{
    public function __construct(private readonly AccountInvitationService $invitations) {}

    public function show(string $token): View|RedirectResponse
    {
        $invitation = $this->invitations->findOpenByPlainToken($token);

        if ($invitation === null) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'This activation link is invalid or has expired.']);
        }

        return view('auth.account-activate', [
            'token' => $token,
            'emailHint' => $this->maskEmail((string) $invitation->user?->email),
            'expiresAt' => $invitation->expires_at,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $request->validate([
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->mixedCase()->letters()->numbers()->symbols(),
            ],
        ]);

        $result = $this->invitations->activate($token, (string) $request->input('password'));

        return match ($result) {
            'ok' => redirect()
                ->route('login')
                ->with('status', 'Your account is active. Sign in with your new password.'),
            'expired' => redirect()
                ->route('login')
                ->withErrors(['email' => 'This activation link has expired. Ask IT to resend the invitation.']),
            'revoked' => redirect()
                ->route('login')
                ->withErrors(['email' => 'This activation link was revoked. Ask IT for a new invitation.']),
            'consumed' => redirect()
                ->route('login')
                ->withErrors(['email' => 'This activation link was already used.']),
            default => redirect()
                ->route('login')
                ->withErrors(['email' => 'This activation link is invalid.']),
        };
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($local === '' || $domain === '') {
            return 'your email';
        }

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
