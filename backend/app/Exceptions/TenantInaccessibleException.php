<?php

namespace App\Exceptions;

class TenantInaccessibleException extends DomainException
{
    public static function suspended(): self
    {
        return new self('This business account is suspended. Please contact AvantPOS support.', 'TENANT_SUSPENDED', 403);
    }

    public static function cancelled(): self
    {
        return new self('This business account has been cancelled.', 'TENANT_CANCELLED', 403);
    }

    /**
     * @param  string|null  $loginUrl  Correct login URL for the user's business.
     */
    public static function mismatch(?string $loginUrl = null): self
    {
        return new self(
            'This account does not belong to this business address.',
            'TENANT_MISMATCH',
            403,
            array_filter(['login_url' => $loginUrl]),
        );
    }
}
