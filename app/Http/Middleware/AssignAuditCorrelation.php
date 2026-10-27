<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a per-request id that audit events may store. This is not a session id.
 */
class AssignAuditCorrelation
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlation = (string) Str::uuid();
        $request->attributes->set('audit_correlation_id', $correlation);
        app()->instance('audit.correlation_id', $correlation);

        return $next($request);
    }
}
