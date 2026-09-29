<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PatientHeader;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the patient dashboard.
     */
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $patient = PatientHeader::from($user);

        $quickActions = [
            [
                'title' => 'Chat with AI Virtual Front Desk',
                'description' => 'Get recommendations based on your symptoms.',
                'variant' => 'blue',
                'icon' => 'chat',
                'href' => '#',
            ],
            [
                'title' => 'Book an Appointment',
                'description' => 'Schedule with your preferred doctor.',
                'variant' => 'green',
                'icon' => 'calendar',
                'href' => route('patient.book-appointment'),
            ],
            [
                'title' => 'View My Appointments',
                'description' => 'Check upcoming and past appointments.',
                'variant' => 'purple',
                'icon' => 'clipboard',
                'href' => route('patient.appointments'),
            ],
            [
                'title' => 'Manage My Profile',
                'description' => 'View your registered information.',
                'variant' => 'pink',
                'icon' => 'user',
                'href' => route('patient.profile'),
            ],
        ];

        $appointment = [
            'month' => 'JUL',
            'day' => '24',
            'year' => '2026',
            'title' => 'General Medicine Consultation',
            'doctor' => 'Dr. Maria Santos, MD',
            'time' => '10:00 AM',
            'location' => 'Outpatient Department',
            'status' => 'Confirmed',
        ];

        $announcements = [
            [
                'title' => 'Holiday Schedule',
                'date' => 'Jul 10, 2025',
                'body' => "Please be advised of the hospital's operating hours this coming holiday.",
            ],
            [
                'title' => 'New Services Available',
                'date' => 'Jul 5, 2025',
                'body' => 'We are now accepting online appointments for select departments.',
            ],
            [
                'title' => 'Patient Advisory',
                'date' => 'Jun 28, 2025',
                'body' => 'Kindly arrive 15 minutes before your scheduled appointment.',
            ],
        ];

        $composerChips = [
            [
                'label' => 'Check my symptoms',
                'icon' => 'sparkle',
                'prompt' => 'Help me check my symptoms.',
            ],
            [
                'label' => 'Find a specialist',
                'icon' => 'badge',
                'prompt' => 'Help me find a specialist.',
            ],
            [
                'label' => 'FAQ',
                'icon' => 'help',
                'prompt' => 'What are the frequently asked questions about appointments?',
            ],
        ];

        return view('patient.dashboard', compact(
            'patient',
            'quickActions',
            'appointment',
            'announcements',
            'composerChips',
        ));
    }
}
