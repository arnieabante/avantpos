<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mails to the business owner about their AvantPOS subscription:
 * renewal reminder, trial ended, payment overdue, expired, ended after cancelling.
 */
class SubscriptionNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $kind, public readonly Subscription $subscription) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->subscription;
        $plan = $s->plan?->name ?? 'your plan';
        // The business's own address (e.g. https://trd-motorshop.avantpos.co), like every other e-mail link.
        $url = app(FrontendUrl::class)->forTenantId($s->tenant_id).'/settings/billing';
        $end = $s->current_period_end?->toFormattedDateString();
        $grace = $s->grace_ends_at?->toFormattedDateString();

        [$subject, $lines, $action] = match ($this->kind) {
            'renewal_reminder' => ["Your AvantPOS {$plan} plan renews on {$end}", ["Your {$plan} subscription ends on {$end}. Your renewal invoice is ready — pay it before then to keep everything running without interruption."], 'Pay renewal'],
            'trial_ended' => ['Your AvantPOS free trial has ended', ["Your free trial of {$plan} has ended. Pay your first invoice by {$grace} to keep using all your features."], 'Choose a plan'],
            'past_due' => ['Your AvantPOS subscription payment is overdue', ["We have not received the payment for {$plan}. You can keep working until {$grace}; after that your account becomes read-only until it is paid."], 'Pay now'],
            'expired' => ['Your AvantPOS subscription has expired', ['Your subscription has expired, so your account is read-only: you can view your records but not record new sales. Pay your invoice to continue right away.'], 'Renew now'],
            'ended' => ['Your AvantPOS subscription has ended', ['Your subscription has ended, so your account is read-only: you can still view and export your records. Your data is kept; choose a plan any time to continue.'], 'Choose a plan'],
            default => ['Your AvantPOS subscription', ['There is an update about your subscription.'], 'Open billing'],
        };

        $mail = (new MailMessage)->subject($subject)->greeting("Hello {$notifiable->name},");
        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail->action($action, $url)->line('Thank you for using AvantPOS.');
    }
}
