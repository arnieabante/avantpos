<?php

namespace App\Rules;

use App\Models\Tenant;
use App\Tenancy\SubdomainResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AvailableSubdomain implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreTenantId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $resolver = app(SubdomainResolver::class);
        $value = strtolower((string) $value);

        if (! $resolver->isValidFormat($value)) {
            $fail('The subdomain may only contain lowercase letters, numbers and hyphens (3-30 characters).');

            return;
        }

        if ($resolver->isReserved($value)) {
            $fail('This subdomain is reserved. Please choose another.');

            return;
        }

        $taken = Tenant::withTrashed()
            ->where('subdomain', $value)
            ->when($this->ignoreTenantId, fn ($q) => $q->whereKeyNot($this->ignoreTenantId))
            ->exists();

        if ($taken) {
            $fail('This subdomain is already taken.');
        }
    }
}
