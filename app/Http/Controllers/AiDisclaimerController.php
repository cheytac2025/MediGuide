<?php

namespace App\Http\Controllers;

use App\Support\AiDisclaimer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiDisclaimerController extends Controller
{
    /**
     * Show the mandatory AI disclaimer, or continue when already acknowledged.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if (AiDisclaimer::isAcknowledged($request->session())) {
            return redirect()->route('ai-front-desk');
        }

        return view('ai.disclaimer', [
            'active' => 'front-desk',
        ]);
    }

    /**
     * Store disclaimer acknowledgement in the current session.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'acknowledged' => ['accepted'],
        ]);

        AiDisclaimer::acknowledge($request->session());

        return redirect()->route('ai-front-desk');
    }
}
