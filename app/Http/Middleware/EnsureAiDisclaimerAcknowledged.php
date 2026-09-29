<?php

namespace App\Http\Middleware;

use App\Support\AiDisclaimer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiDisclaimerAcknowledged
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! AiDisclaimer::isAcknowledged($request->session())) {
            return redirect()->route('ai-disclaimer');
        }

        return $next($request);
    }
}
