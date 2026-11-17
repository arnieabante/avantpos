<?php

namespace App\Services\Billing\Gateways;

use App\Models\SubscriptionInvoice;
use Illuminate\Http\Request;

/**
 * Bank transfer / e-wallet transfer to AvantPOS's account. The business
 * sends the reference number; an AvantPOS admin verifies it.
 */
class ManualGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'manual';
    }

    public function available(): bool
    {
        return true;
    }

    public function checkout(SubscriptionInvoice $invoice, string $customerEmail, string $customerName): CheckoutSession
    {
        return new CheckoutSession(null, null);
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): ?WebhookResult
    {
        return null;
    }
}
