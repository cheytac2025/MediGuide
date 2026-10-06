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
    | verified — those records have been replaced with verified hospital data.
    |
    | The grounding service only reads this marker. Eligibility, validation,
    | and the guidance contract stay the same when the source changes.
    | Use "verified" only after the placeholder records have been replaced.
    |
    */

    'data_source' => env('HOSPITAL_DATA_SOURCE', 'development'),

];
