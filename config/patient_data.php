<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy plaintext transition
    |--------------------------------------------------------------------------
    |
    | Records written before application-level encryption was introduced are
    | stored as plaintext. While true, encrypted columns still read those
    | legacy values. Set to false only after `php artisan
    | patient-data:encrypt` reports zero plaintext values; any remaining
    | plaintext will then be refused instead of displayed.
    |
    */

    'allow_legacy_plaintext' => (bool) env('PATIENT_DATA_ALLOW_LEGACY_PLAINTEXT', true),

    /*
    |--------------------------------------------------------------------------
    | Patient profile field encryption
    |--------------------------------------------------------------------------
    |
    | patients.contact_number (VARCHAR 20) and patients.date_of_birth (DATE)
    | cannot hold ciphertext until the widening migration has run. Keep this
    | false until that migration is applied; new values are then stored
    | encrypted. Reads accept both formats either way.
    |
    */

    'encrypt_profile_fields' => (bool) env('PATIENT_DATA_ENCRYPT_PROFILE_FIELDS', false),

];
