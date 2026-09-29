<?php

use App\Enums\RoleName;
use App\Http\Controllers\AiDisclaimerController;
use App\Http\Controllers\Auth\RegisteredPatientController;
use App\Http\Controllers\DashboardUnavailableController;
use App\Http\Controllers\Patient\AiFrontDeskController;
use App\Http\Controllers\Patient\BookAppointmentController;
use App\Http\Controllers\Patient\DashboardController;
use App\Http\Controllers\Patient\PatientAppointmentController;
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

    Route::middleware('role:'.RoleName::Patient->value)->group(function () {
        Route::get('/patient/dashboard', DashboardController::class)
            ->name('patient.dashboard');

        Route::get('/patient/book-appointment', [BookAppointmentController::class, 'create'])
            ->name('patient.book-appointment');
        Route::post('/patient/book-appointment/review', [BookAppointmentController::class, 'review'])
            ->name('patient.book-appointment.review');
        Route::post('/patient/book-appointment', [BookAppointmentController::class, 'store'])
            ->name('patient.book-appointment.store');

        Route::get('/patient/appointments', [PatientAppointmentController::class, 'index'])
            ->name('patient.appointments');
        Route::get('/patient/appointments/{appointment}', [PatientAppointmentController::class, 'show'])
            ->whereNumber('appointment')
            ->name('patient.appointments.show');
        Route::patch('/patient/appointments/{appointment}/cancel', [PatientAppointmentController::class, 'cancel'])
            ->whereNumber('appointment')
            ->name('patient.appointments.cancel');
    });
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
