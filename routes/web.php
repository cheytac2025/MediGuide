<?php

use App\Enums\RoleName;
use App\Http\Controllers\AiDisclaimerController;
use App\Http\Controllers\Auth\RegisteredPatientController;
use App\Http\Controllers\DashboardUnavailableController;
use App\Http\Controllers\Patient\AiFrontDeskController;
use App\Http\Controllers\Patient\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AiDisclaimerController::class, 'show'])->name('home');

Route::get('/ai-disclaimer', [AiDisclaimerController::class, 'show'])->name('ai-disclaimer');
Route::post('/ai-disclaimer', [AiDisclaimerController::class, 'store'])->name('ai-disclaimer.acknowledge');

Route::get('/ai-front-desk', AiFrontDeskController::class)
    ->middleware('ai.disclaimer')
    ->name('ai-front-desk');

Route::redirect('/patient/ai-front-desk', '/ai-front-desk')->name('patient.ai-front-desk');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard/unavailable', DashboardUnavailableController::class)
        ->name('dashboard.unavailable');

    Route::get('/patient/dashboard', DashboardController::class)
        ->middleware('role:'.RoleName::Patient->value)
        ->name('patient.dashboard');
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
