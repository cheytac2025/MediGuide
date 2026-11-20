<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Require verified email for patient workflows
    |--------------------------------------------------------------------------
    |
    | When false (default), unverified patients keep access. Production must
    | stay false until outbound mail is verified and the transition policy is
    | approved. When true, new patients must verify before protected routes;
    | grandfathered accounts follow the notice/grace rules below.
    |
    */

    'require_verified_patient' => filter_var(
        env('AUTH_REQUIRE_VERIFIED_PATIENT', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    |--------------------------------------------------------------------------
    | Grandfather strategy B
    |--------------------------------------------------------------------------
    |
    | Accounts created at or after new_account_after are treated as new and
    | must verify immediately when require_verified_patient is true.
    |
    | Accounts created before that timestamp remain accessible until
    | grace_until (inclusive notice period). After grace_until they must
    | verify. An empty grace_until keeps grandfathered accounts accessible
    | with a notice only (no lockout).
    |
    | new_account_after empty => treat every unverified patient as grandfathered.
    |
    */

    'new_account_after' => env('AUTH_VERIFICATION_NEW_ACCOUNT_AFTER'),

    'grace_until' => env('AUTH_VERIFICATION_GRACE_UNTIL'),

    /*
    |--------------------------------------------------------------------------
    | Optional patient email OTP
    |--------------------------------------------------------------------------
    |
    | When enabled, patients who opted in (users.email_otp_enabled) complete an
    | email OTP challenge after password/TOTP login. Privileged MFA remains TOTP.
    | Keep false until mail delivery is proven.
    |
    */

    'email_otp_enabled' => filter_var(
        env('AUTH_EMAIL_OTP_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'email_otp_ttl_seconds' => (int) env('AUTH_EMAIL_OTP_TTL_SECONDS', 300),

    'email_otp_max_attempts' => (int) env('AUTH_EMAIL_OTP_MAX_ATTEMPTS', 5),

    'email_otp_resend_cooldown_seconds' => (int) env('AUTH_EMAIL_OTP_RESEND_COOLDOWN_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Privileged TOTP enforcement
    |--------------------------------------------------------------------------
    |
    | When true, hospital-staff, doctor, and it-administrator accounts must
    | have confirmed Fortify TOTP. Keep false on VPS until separately approved.
    |
    */

    'require_totp_privileged' => filter_var(
        env('AUTH_REQUIRE_TOTP_PRIVILEGED', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

];
