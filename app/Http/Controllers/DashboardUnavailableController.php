<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DashboardUnavailableController extends Controller
{
    /**
     * Temporary landing page for authenticated roles whose dashboards are not built yet.
     */
    public function __invoke(): View
    {
        return view('auth.dashboard-unavailable');
    }
}
