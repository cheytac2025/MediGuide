<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hospital data source
    |--------------------------------------------------------------------------
    |
    | Identifies the hospital service records currently stored in MediGuide.
    |
    | development — placeholder records. Not verified Queen Mary information.
    | imported — records loaded from a supplied hospital schedule. Pending verification.
    | verified — authorized hospital personnel have confirmed the stored records.
    |
    | The grounding service only reads this marker. Eligibility, validation,
    | and the guidance contract stay the same when the source changes.
    | Use "verified" only after the placeholder records have been replaced.
    |
    */

    'data_source' => env('HOSPITAL_DATA_SOURCE', 'development'),

    /*
    |--------------------------------------------------------------------------
    | Provisional local demonstration schedules
    |--------------------------------------------------------------------------
    |
    | Poster-derived DoctorSchedule rows are marked provisional. They are
    | offered for booking only when this flag is true, the application
    | environment is local, and the database host is this machine.
    | Copying the database to another environment does not enable them.
    | Never set this true in production.
    |
    */

    'provisional_local_booking' => filter_var(env('HOSPITAL_PROVISIONAL_LOCAL_BOOKING', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Isolated online demonstration instance
    |--------------------------------------------------------------------------
    |
    | When true, provisional demonstration slots may be offered outside the
    | local-loopback developer machine — but only when the connected database
    | name exactly matches demo_database and is not a blocked production name.
    | Keep false on the public production application.
    |
    */

    'demo_instance' => filter_var(env('MEDIGUIDE_DEMO_INSTANCE', false), FILTER_VALIDATE_BOOLEAN),

    'demo_database' => env('MEDIGUIDE_DEMO_DATABASE', ''),

    'demo_banner' => env(
        'MEDIGUIDE_DEMO_BANNER',
        'CAPSTONE DEMONSTRATION — schedules and appointments are synthetic and not real hospital bookings.',
    ),

    /*
    |--------------------------------------------------------------------------
    | Optional basic-auth gate for a public demo hostname
    |--------------------------------------------------------------------------
    |
    | When set (non-empty user and password), Nginx/demo deploy scripts may
    | require HTTP basic authentication before the Laravel app is reached.
    | Application code does not enforce these values; they document intent.
    |
    */

    'demo_basic_auth_user' => env('MEDIGUIDE_DEMO_BASIC_AUTH_USER', ''),

    /*
    |--------------------------------------------------------------------------
    | Capstone defense booking on the existing public site
    |--------------------------------------------------------------------------
    |
    | When enabled, provisional demonstration schedules become visible for AI
    | recommendations and calendars, but only allowlisted synthetic accounts
    | may confirm bookings. Keep HOSPITAL_PROVISIONAL_LOCAL_BOOKING=false so
    | the public site does not offer unrestricted provisional booking.
    |
    | MEDIGUIDE_CAPSTONE_BOOKING_ACCOUNTS is a comma-separated email list.
    |
    */

    'capstone_booking' => filter_var(env('MEDIGUIDE_CAPSTONE_BOOKING', false), FILTER_VALIDATE_BOOLEAN),

    'capstone_booking_accounts' => array_values(array_filter(array_map(
        static fn (string $email): string => mb_strtolower(trim($email)),
        explode(',', (string) env('MEDIGUIDE_CAPSTONE_BOOKING_ACCOUNTS', '')),
    ))),

];
