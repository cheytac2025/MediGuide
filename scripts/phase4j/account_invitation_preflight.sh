#!/usr/bin/env bash
# Phase 4J — read-only preflight before account-invitation / all-role OTP deploy.
# Does NOT migrate, pull, restart services, change flags, or send email.
set -euo pipefail

APP="${APP_ROOT:-/var/www/mediguide}"
cd "$APP"

echo "HOST=$(hostname)"
echo "GIT=$(git rev-parse HEAD 2>/dev/null || echo unknown)"
echo "GIT_SHORT=$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
echo "BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
echo "DIRTY=$(git status --porcelain | wc -l | tr -d ' ')"

php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = config('database.connections.mysql.database');
echo 'DB='.$db.PHP_EOL;
echo 'DB_OK='.($db === 'mediguide_demo' ? 'yes' : 'NO').PHP_EOL;

$flags = [
    'AUTH_REQUIRE_VERIFIED_PATIENT' => config('auth_security.require_verified_patient'),
    'AUTH_ENFORCE_EMAIL_VERIFICATION' => config('auth_security.enforce_email_verification'),
    'AUTH_EMAIL_OTP_ENABLED' => config('auth_security.email_otp_enabled'),
    'AUTH_REQUIRE_EMAIL_OTP_ALL_ROLES' => config('auth_security.require_email_otp_all_roles'),
    'AUTH_REQUIRE_TOTP_PRIVILEGED' => config('auth_security.require_totp_privileged'),
    'AUTH_ACCOUNT_INVITATION_TTL_HOURS' => config('auth_security.account_invitation_ttl_hours'),
];
foreach ($flags as $k => $v) {
    echo $k.'='.(is_bool($v) ? ($v ? 'true' : 'false') : $v).PHP_EOL;
}

echo 'MAIL_MAILER='.config('mail.default').PHP_EOL;
echo 'MAIL_HOST='.config('mail.mailers.smtp.host').PHP_EOL;
echo 'ANTHROPIC_KEY='.(filled(config('services.anthropic.key')) ? 'present' : 'MISSING').PHP_EOL;
echo 'APP_KEY='.(filled(config('app.key')) ? 'present' : 'MISSING').PHP_EOL;
echo 'APP_DEBUG='.(config('app.debug') ? 'true' : 'false').PHP_EOL;

$migrated = Illuminate\Support\Facades\Schema::hasTable('account_invitations') ? 'yes' : 'no';
echo 'ACCOUNT_INVITATIONS_TABLE='.$migrated.PHP_EOL;
PHP

echo "FPM=$(systemctl is-active php8.4-fpm 2>/dev/null || echo unknown)"
echo "QUEUE=$(systemctl is-active mediguide-queue 2>/dev/null || echo unknown)"
echo "NGINX=$(systemctl is-active nginx 2>/dev/null || echo unknown)"

echo "PREFLIGHT_DONE=yes"
echo "NOTE: Do not enable AUTH_REQUIRE_EMAIL_OTP_ALL_ROLES or AUTH_REQUIRE_TOTP_PRIVILEGED in this release."
