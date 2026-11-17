<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base class for expected business-rule failures. Rendered as
 * { "message": ..., "code": ..., "errors"?: ... } with the given HTTP status.
 *
 * Subclass per rule (e.g. NoOpenSessionException) so callers can catch
 * specific cases and the frontend can switch on a stable `code`.
 */
class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  Extra data returned to the client (never secrets).
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'DOMAIN_ERROR',
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
