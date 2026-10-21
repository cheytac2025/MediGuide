<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Patient booking window
    |--------------------------------------------------------------------------
    |
    | How many calendar days ahead of today a patient may book, in the
    | hospital timezone. 7 includes today through the date seven days ahead:
    | eight calendar dates in total. Day 8 is outside the window.
    |
    */

    'booking_window_days' => (int) env('APPOINTMENT_BOOKING_WINDOW_DAYS', 7),

];
