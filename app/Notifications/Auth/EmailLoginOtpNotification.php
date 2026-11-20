<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Non-clinical login OTP email. The plaintext code is only present while the
 * notification is being rendered and is never persisted by this class.
 */
class EmailLoginOtpNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = max(1, (int) ceil(((int) config('auth_security.email_otp_ttl_seconds', 300)) / 60));

        return (new MailMessage)
            ->subject('MediGuide sign-in code')
            ->greeting('Sign-in verification')
            ->line('Use this one-time code to finish signing in to MediGuide.')
            ->line('Your code: '.$this->code)
            ->line("This code expires in {$minutes} minutes and can be used only once.")
            ->line('If you did not try to sign in, you can ignore this message.')
            ->salutation('MediGuide');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
