<?php

namespace App\Services\Billing\Gateways;

use App\Exceptions\DomainException;

/**
 * Picks the payment gateway: the configured one when it is set up, else
 * manual bank transfer. Add a provider by listing its class here.
 */
class GatewayManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    private const DRIVERS = [
        'xendit' => XenditGateway::class,
        'paymongo' => PayMongoGateway::class,
        'manual' => ManualGateway::class,
    ];

    public function get(string $key): PaymentGateway
    {
        $class = self::DRIVERS[$key] ?? throw new DomainException("Unknown payment gateway {$key}.", 'GATEWAY_UNKNOWN', 404);

        return app($class);
    }

    /** The gateway offered at checkout. */
    public function default(): PaymentGateway
    {
        $gateway = $this->get((string) config('avantpos.billing.gateway', 'manual'));

        return $gateway->available() ? $gateway : $this->get('manual');
    }
}
