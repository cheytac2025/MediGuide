<?php

namespace App\Http\Controllers\Auth;

use App\Enums\RoleName;
use App\Enums\Sex;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterPatientRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class RegisteredPatientController extends Controller
{
    public function create(): View
    {
        return view('auth.register', [
            'sexOptions' => Sex::cases(),
        ]);
    }

    public function store(RegisterPatientRequest $request): RedirectResponse
    {
        $validated = $request->safe()->only([
            'first_name',
            'middle_name',
            'last_name',
            'email',
            'password',
            'date_of_birth',
            'sex',
            'contact_number',
        ]);

        try {
            DB::transaction(function () use ($validated): void {
                $patientRole = Role::query()
                    ->where('slug', RoleName::Patient->value)
                    ->firstOrFail();

                $user = User::query()->create([
                    'role_id' => $patientRole->id,
                    'first_name' => $validated['first_name'],
                    'middle_name' => $validated['middle_name'] ?? null,
                    'last_name' => $validated['last_name'],
                    'name' => $this->fullName(
                        $validated['first_name'],
                        $validated['middle_name'] ?? null,
                        $validated['last_name'],
                    ),
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'status' => UserStatus::Active,
                ]);

                $user->patient()->create([
                    'date_of_birth' => $validated['date_of_birth'],
                    'sex' => $validated['sex'],
                    'contact_number' => $this->storeContactNumber($validated['contact_number']),
                ]);
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors([
                    'email' => 'We could not create your account. Please try again.',
                ]);
        }

        return redirect()
            ->route('login')
            ->with('status', 'Account created successfully. Please sign in using your registered email and password.');
    }

    private function fullName(string $firstName, ?string $middleName, string $lastName): string
    {
        return collect([$firstName, $middleName, $lastName])
            ->filter()
            ->implode(' ');
    }

    private function storeContactNumber(string $contactNumber): string
    {
        if (str_starts_with($contactNumber, '+63')) {
            return '0'.substr($contactNumber, 3);
        }

        return $contactNumber;
    }
}
