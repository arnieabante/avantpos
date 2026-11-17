<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $businessName,
        public readonly string $roleName,
        public readonly string $acceptUrl,
        public readonly CarbonInterface $expiresAt,
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
        return (new MailMessage)
            ->subject("You're invited to join {$this->businessName} on AvantPOS")
            ->greeting('Hello!')
            ->line("You have been invited to join **{$this->businessName}** as **{$this->roleName}**.")
            ->action('Accept invitation', $this->acceptUrl)
            ->line('This invitation expires on '.$this->expiresAt->timezone('Asia/Manila')->format('M d, Y h:i A').' (PH time).')
            ->line('If you were not expecting this invitation, you can ignore this e-mail.');
    }
}
