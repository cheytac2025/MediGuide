<?php

namespace App\Http\Controllers\Doctor\Concerns;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait InteractsWithLinkedDoctor
{
    protected function linkedDoctor(Request $request): Doctor|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('doctor');

        $doctor = $user->doctor;

        if ($doctor === null) {
            return redirect()->route('dashboard.unavailable');
        }

        return $doctor;
    }
}
