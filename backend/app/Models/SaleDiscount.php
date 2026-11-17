<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discount applied on a sale (line or transaction). For Senior Citizen /
 * PWD discounts the beneficiary's name and ID are kept for the BIR SC/PWD
 * sales book; the ID number is encrypted at rest.
 */
class SaleDiscount extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected $hidden = ['beneficiary_id_no'];

    protected function casts(): array
    {
        return ['value' => 'decimal:4', 'amount' => 'decimal:2', 'beneficiary_id_no' => 'encrypted'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
