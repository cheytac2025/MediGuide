<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Account names and login emails are managed by the IT Administrator,
     * so self-service changes are refused for every role.
     */
    public function update(Request $request): RedirectResponse
    {
        abort(403);
    }

    /**
     * Accounts are deactivated by the IT Administrator, never self-deleted,
     * because appointment history must remain attached to its owner.
     */
    public function destroy(Request $request): RedirectResponse
    {
        abort(403);
    }
}
