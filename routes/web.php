<?php

use App\Enums\RoleName;
use App\Http\Controllers\AiDisclaimerController;
use App\Http\Controllers\Auth\RegisteredPatientController;
use App\Http\Controllers\DashboardUnavailableController;
use App\Http\Controllers\Patient\AiBookingIntentController;
use App\Http\Controllers\Patient\AiClinicDoctorsController;
use App\Http\Controllers\Patient\AiFrontDeskController;
use App\Http\Controllers\Patient\BookAppointmentController;
use App\Http\Controllers\Patient\DashboardController;
use App\Http\Controllers\Patient\NotificationController;
use App\Http\Controllers\Patient\PatientAppointmentController;
use App\Http\Controllers\Patient\ProfileController;
use App\Http\Controllers\Doctor\DashboardController as DoctorDashboardController;
use App\Http\Controllers\Doctor\DoctorAppointmentController;
use App\Http\Controllers\Doctor\DoctorScheduleController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\Staff\StaffAppointmentController;
use App\Http\Controllers\Staff\StaffDoctorScheduleController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AiDisclaimerController::class, 'show'])->name('home');

Route::get('/ai-disclaimer', [AiDisclaimerController::class, 'show'])->name('ai-disclaimer');
Route::post('/ai-disclaimer', [AiDisclaimerController::class, 'store'])->name('ai-disclaimer.acknowledge');

Route::middleware('ai.disclaimer')->group(function () {
    Route::get('/ai-front-desk', AiFrontDeskController::class)
        ->name('ai-front-desk');

    Route::get('/ai-front-desk/clinics/{clinic}/doctors', AiClinicDoctorsController::class)
        ->whereNumber('clinic')
        ->name('ai-front-desk.clinics.doctors');

    Route::post('/ai-front-desk/booking-intent', [AiBookingIntentController::class, 'store'])
        ->name('ai-front-desk.booking-intent');
});

Route::redirect('/patient/ai-front-desk', '/ai-front-desk')->name('patient.ai-front-desk');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard/unavailable', DashboardUnavailableController::class)
        ->name('dashboard.unavailable');

    Route::middleware('role:'.RoleName::Patient->value)->group(function () {
        Route::get('/patient/dashboard', DashboardController::class)
            ->name('patient.dashboard');

        Route::get('/patient/profile', ProfileController::class)
            ->name('patient.profile');

        Route::get('/patient/notifications', [NotificationController::class, 'index'])
            ->name('patient.notifications');
        Route::patch('/patient/notifications/read-all', [NotificationController::class, 'markAllRead'])
            ->name('patient.notifications.read-all');
        Route::patch('/patient/notifications/{notification}/read', [NotificationController::class, 'markRead'])
            ->name('patient.notifications.read');
        Route::get('/patient/notifications/{notification}/open', [NotificationController::class, 'open'])
            ->name('patient.notifications.open');

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

    Route::middleware('role:'.RoleName::Doctor->value)->group(function () {
        Route::get('/doctor/dashboard', DoctorDashboardController::class)
            ->name('doctor.dashboard');

        Route::get('/doctor/appointments', [DoctorAppointmentController::class, 'index'])
            ->name('doctor.appointments');
        Route::get('/doctor/appointments/{appointment}', [DoctorAppointmentController::class, 'show'])
            ->whereNumber('appointment')
            ->name('doctor.appointments.show');

        Route::get('/doctor/schedule', DoctorScheduleController::class)
            ->name('doctor.schedule');
    });

    Route::middleware('role:'.RoleName::HospitalStaff->value)->group(function () {
        Route::get('/staff/dashboard', StaffDashboardController::class)
            ->name('staff.dashboard');

        Route::get('/staff/appointments', [StaffAppointmentController::class, 'index'])
            ->name('staff.appointments');
        Route::get('/staff/appointments/{appointment}', [StaffAppointmentController::class, 'show'])
            ->whereNumber('appointment')
            ->name('staff.appointments.show');
        Route::patch('/staff/appointments/{appointment}/confirm', [StaffAppointmentController::class, 'confirm'])
            ->whereNumber('appointment')
            ->name('staff.appointments.confirm');
        Route::patch('/staff/appointments/{appointment}/reject', [StaffAppointmentController::class, 'reject'])
            ->whereNumber('appointment')
            ->name('staff.appointments.reject');

        Route::get('/staff/doctor-schedules', [StaffDoctorScheduleController::class, 'index'])
            ->name('staff.doctor-schedules');
        Route::get('/staff/doctor-schedules/{doctor}', [StaffDoctorScheduleController::class, 'show'])
            ->whereNumber('doctor')
            ->name('staff.doctor-schedules.show');
        Route::post('/staff/doctor-schedules/{doctor}', [StaffDoctorScheduleController::class, 'store'])
            ->whereNumber('doctor')
            ->name('staff.doctor-schedules.store');
        Route::patch('/staff/doctor-schedules/{doctorSchedule}', [StaffDoctorScheduleController::class, 'update'])
            ->whereNumber('doctorSchedule')
            ->name('staff.doctor-schedules.update');
        Route::patch('/staff/doctor-schedules/{doctorSchedule}/status', [StaffDoctorScheduleController::class, 'updateStatus'])
            ->whereNumber('doctorSchedule')
            ->name('staff.doctor-schedules.status');
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
