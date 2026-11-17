<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-owned data is accessed without a tenant context.
 * This always indicates a programming error, never a user error — it is
 * the safety net that prevents unscoped (cross-tenant) queries.
 */
class MissingTenantContextException extends RuntimeException
{
    public function __construct(string $message = 'No tenant context is set for a tenant-scoped operation.')
    {
        parent::__construct($message);
    }
}
