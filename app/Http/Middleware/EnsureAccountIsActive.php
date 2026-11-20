<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditTrailService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends any authenticated session, remember-me login, or passkey login
 * whose account is not Active (inactive or pending activation).
 */
class EnsureAccountIsActive
{
    public const MESSAGE = 'Your account is inactive. Please contact the hospital IT administrator.';

    public const PENDING_MESSAGE = 'Your account is not activated yet. Use the invitation email from IT, or ask them to resend it.';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user === null || $user->status === UserStatus::Active) {
            return $next($request);
        }

        $pending = $user->status === UserStatus::PendingActivation;
        $message = $pending ? self::PENDING_MESSAGE : self::MESSAGE;

        app(AuditTrailService::class)->recordSafely(
            AuditAction::InactiveSessionDenied,
            AuditOutcome::Denied,
            'user',
            (int) $user->id,
            ['reason' => $pending ? 'pending_activation' : 'inactive'],
            $user instanceof User ? $user : null,
        );

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
