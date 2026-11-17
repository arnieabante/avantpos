<?php

namespace App\Exceptions;

class PlanLimitReachedException extends DomainException
{
    public static function for(string $resource, int $limit): self
    {
        return new self(
            "Your plan allows up to {$limit} {$resource}. Upgrade your subscription to add more.",
            'PLAN_LIMIT_REACHED',
            402,
            ['resource' => $resource, 'limit' => $limit],
        );
    }
}
