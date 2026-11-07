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

];
