<?php

namespace App\Rules;

use App\Support\PhilippinePhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a (pre-normalized) Philippine phone number.
 * Use `new PhilippinePhoneNumber(mobileOnly: true)` to require a mobile number.
 */
class PhilippinePhoneNumber implements ValidationRule
{
    public function __construct(private readonly bool $mobileOnly = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = PhilippinePhone::normalize(is_string($value) ? $value : null);

        $ok = $this->mobileOnly ? PhilippinePhone::isMobile($normalized) : PhilippinePhone::isValid($normalized);

        if (! $ok) {
            $fail($this->mobileOnly
                ? 'The :attribute must be a valid Philippine mobile number (e.g. 0917 123 4567).'
                : 'The :attribute must be a valid Philippine phone number.');
        }
    }
}
