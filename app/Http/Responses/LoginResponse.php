<?php

namespace App\Http\Responses;

use App\Enums\RoleName;
use App\Models\User;
use App\Support\AuthenticatedHome;
use App\Support\BookingIntent;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasRole(RoleName::Patient)) {
            $intent = BookingIntent::consumeValidated($request->session());

            if ($intent !== null) {
                return redirect()->route('patient.book-appointment', $intent);
            }
        } else {
            BookingIntent::forget($request->session());
        }

        return redirect()->intended(AuthenticatedHome::route($user));
    }
}
