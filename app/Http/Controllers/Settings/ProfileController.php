<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditTrailService;
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
        $this->denyProfileChange($request, 'profile_update');
    }

    /**
     * Accounts are deactivated by the IT Administrator, never self-deleted,
     * because appointment history must remain attached to its owner.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->denyProfileChange($request, 'profile_delete');
    }

    private function denyProfileChange(Request $request, string $reason): never
    {
        $user = $request->user();

        app(AuditTrailService::class)->recordSafely(
            AuditAction::AuthorizationDenied,
            AuditOutcome::Denied,
            $user instanceof User ? 'user' : null,
            $user instanceof User ? (int) $user->id : null,
            ['reason' => $reason],
            $user instanceof User ? $user : null,
        );

        abort(403);
    }
}
