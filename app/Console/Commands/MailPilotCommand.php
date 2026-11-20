<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends exactly one non-clinical connectivity message.
 * Does not touch appointments, patients, or auth enforcement flags.
 */
class MailPilotCommand extends Command
{
    protected $signature = 'mediguide:mail-pilot
        {recipient : Authorized synthetic/test recipient email}
        {--execute : Actually send (otherwise dry-run)}
        {--approval= : Must equal APPROVED send MediGuide mail pilot}';

    protected $description = 'Send one non-clinical MediGuide mail-pilot message';

    public function handle(): int
    {
        $recipient = strtolower(trim((string) $this->argument('recipient')));
        $approval = (string) $this->option('approval');
        $execute = (bool) $this->option('execute');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error('Recipient must be a valid email address.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $host = (string) config('mail.mailers.smtp.host');
        $from = (string) config('mail.from.address');

        $this->line('Mode: '.($execute ? 'SEND' : 'DRY RUN'));
        $this->line('MAIL_MAILER='.$mailer);
        $this->line('SMTP_HOST='.$host);
        $this->line('FROM_ADDRESS='.$from);
        $this->line('RECIPIENT_DOMAIN='.(str_contains($recipient, '@') ? substr(strrchr($recipient, '@'), 1) : 'invalid'));
        $this->line('AUTH_REQUIRE_VERIFIED_PATIENT='.(config('auth_security.require_verified_patient') ? 'true' : 'false'));
        $this->line('AUTH_EMAIL_OTP_ENABLED='.(config('auth_security.email_otp_enabled') ? 'true' : 'false'));

        if ($approval !== 'APPROVED send MediGuide mail pilot') {
            $this->error('Missing or invalid --approval phrase.');

            return self::FAILURE;
        }

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER is still log. Configure SMTP before a real delivery pilot.');
        }

        if (! $execute) {
            $this->info('Dry-run only. No message sent.');

            return self::SUCCESS;
        }

        try {
            Mail::raw(
                "MediGuide mail pilot\n\n".
                "This is a non-clinical connectivity test for the capstone demonstration.\n".
                "It contains no patient, appointment, or clinical information.\n".
                'Sent at '.now()->toIso8601String()."\n",
                function ($message) use ($recipient): void {
                    $message->to($recipient)
                        ->subject('MediGuide mail pilot (non-clinical)');
                },
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Delivery failed. MAIL_PASSWORD was not printed. Check laravel.log for a redacted transport error.');

            return self::FAILURE;
        }

        $this->info('Pilot message accepted by the mail transport.');

        return self::SUCCESS;
    }
}
