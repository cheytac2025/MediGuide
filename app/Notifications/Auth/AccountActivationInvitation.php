<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Non-clinical account activation / claim invitation.
 * Plaintext token exists only while rendering the mail.
 * Links are Laravel temporary-signed URLs; the token is also hashed at rest.
 */
class AccountActivationInvitation extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $plainToken,
        private readonly string $purposeLabel,
        private readonly int $expiresInHours,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'account.activate.show',
            now()->addHours($this->expiresInHours),
            ['token' => $this->plainToken],
        );

        return (new MailMessage)
            ->subject('MediGuide account '.$this->purposeLabel)
            ->greeting('MediGuide account '.$this->purposeLabel)
            ->line('An IT administrator invited you to activate your MediGuide account.')
            ->line('This invitation expires in '.$this->expiresInHours.' hours and can be used only once.')
            ->action('Activate account', $url)
            ->line('If you did not expect this message, contact the hospital IT administrator.')
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
