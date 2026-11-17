<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** Whether the tenant may create new transactions (otherwise read-only). */
    public function allowsWrites(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue, self::Grace], true);
    }
}
