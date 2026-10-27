<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Support\Audit\AuditTrailService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if ($user === null || $user->role?->slug !== $role) {
            app(AuditTrailService::class)->recordSafely(
                AuditAction::AuthorizationDenied,
                AuditOutcome::Denied,
                context: ['reason' => 'role', 'role' => $role],
                actor: $user instanceof User ? $user : null,
            );

            abort(403);
        }

        return $next($request);
    }
}
