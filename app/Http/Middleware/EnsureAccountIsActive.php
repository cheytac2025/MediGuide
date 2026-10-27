<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends any authenticated session, remember-me login, or passkey login
 * whose account has been deactivated since it was established.
 */
class EnsureAccountIsActive
{
    public const MESSAGE = 'Your account is inactive. Please contact the hospital IT administrator.';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user === null || $user->status === UserStatus::Active) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 401);
        }

        return redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
    }
}
