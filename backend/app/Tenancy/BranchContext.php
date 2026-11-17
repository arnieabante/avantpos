<?php

namespace App\Tenancy;

use App\Models\Branch;

/**
 * Branch selected for the current request (validated X-Branch-Id header).
 */
class BranchContext
{
    private ?Branch $branch = null;

    public function set(Branch $branch): void
    {
        $this->branch = $branch;
    }

    public function forget(): void
    {
        $this->branch = null;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function id(): ?int
    {
        return $this->branch?->getKey();
    }
}
