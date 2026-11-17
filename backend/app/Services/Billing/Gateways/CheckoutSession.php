<?php

namespace App\Services\Billing\Gateways;

final class CheckoutSession
{
    public function __construct(
        public readonly ?string $gatewayInvoiceId,
        public readonly ?string $checkoutUrl,
    ) {}
}
