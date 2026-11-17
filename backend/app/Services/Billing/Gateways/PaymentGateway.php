<?php

namespace App\Services\Billing\Gateways;

use App\Models\SubscriptionInvoice;
use Illuminate\Http\Request;

/**
 * A payment provider for AvantPOS subscription invoices. Each provider
 * (Xendit, PayMongo, manual bank transfer, …) is independent: add one by
 * implementing this interface and registering it in GatewayManager.
 */
interface PaymentGateway
{
    /** Short key stored on invoices and payments, e.g. "xendit". */
    public function key(): string;

    /** Whether the provider is configured (keys present) and can be offered. */
    public function available(): bool;

    /**
     * Start a payment for the invoice. Returns the provider's id for it and
     * the page where the customer pays (null when paying offline, e.g. bank transfer).
     */
    public function checkout(SubscriptionInvoice $invoice, string $customerEmail, string $customerName): CheckoutSession;

    /** Whether the webhook really comes from the provider (signature / token). */
    public function verifyWebhook(Request $request): bool;

    /** Read a verified webhook; null when it is not about a payment we track. */
    public function parseWebhook(Request $request): ?WebhookResult;
}
