<?php

namespace App\Services\Billing\Gateways;

/**
 * What a payment webhook says, in provider-independent terms.
 */
final class WebhookResult
{
    /**
     * @param  'paid'|'expired'|'failed'  $status
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $status,
        public readonly ?string $gatewayInvoiceId,
        public readonly ?string $invoiceNumber,
        public readonly ?string $amount,
        public readonly ?string $channel,
        public readonly ?string $reference,
        public readonly array $raw,
    ) {}
}
