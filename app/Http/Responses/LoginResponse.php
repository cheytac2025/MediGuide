<?php

namespace App\Http\Responses;

use App\Support\AuthenticatedHome;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        return redirect()->intended(AuthenticatedHome::route($request->user()));
    }
}
