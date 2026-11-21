<?php

namespace App\Http\Middleware;

use App\Support\Demo\DemoBookingEnvironment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares a visible demonstration banner when this process is an authorized
 * isolated demo instance. Does not alter authorization or booking rules.
 */
class ShareDemoEnvironmentBanner
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $active = DemoBookingEnvironment::shouldShowBanner($request->user());

        View::share('mediguideDemoBanner', $active ? DemoBookingEnvironment::bannerMessage() : null);

        return $next($request);
    }
}
