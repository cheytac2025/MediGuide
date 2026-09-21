<?php

use App\Enums\RoleName;
use App\Http\Controllers\Auth\RegisteredPatientController;
use App\Http\Controllers\DashboardUnavailableController;
use App\Http\Controllers\Patient\AiFrontDeskController;
use App\Http\Controllers\Patient\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard/unavailable', DashboardUnavailableController::class)
        ->name('dashboard.unavailable');

    Route::get('/patient/dashboard', DashboardController::class)
        ->middleware('role:'.RoleName::Patient->value)
        ->name('patient.dashboard');

    Route::get('/patient/ai-front-desk', AiFrontDeskController::class)
        ->middleware('role:'.RoleName::Patient->value)
        ->name('patient.ai-front-desk');
});

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredPatientController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredPatientController::class, 'store'])
        ->middleware('throttle:register')
        ->name('register.store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
