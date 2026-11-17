<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Branch-specific price that overrides the variant's selling/wholesale price.
 */
#[Fillable(['branch_id', 'product_variant_id', 'selling_price', 'wholesale_price'])]
class BranchProductPrice extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['selling_price' => 'decimal:2', 'wholesale_price' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
