<?php

namespace App\Http\Resources\Customers;

use App\Http\Resources\ApiResource;
use App\Models\Customer;
use App\Support\Money\Money;
use Illuminate\Http\Request;

/**
 * @property Customer $resource
 */
class CustomerResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $canEdit = (bool) $request->user()?->can('customers.manage');
        $limit = Money::of($c->credit_limit ?? '0');
        $owed = Money::of($c->balance ?? '0');

        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'phone' => $c->phone,
            'email' => $c->email,
            'tin' => $c->tin,
            'address' => $c->address,
            'birthdate' => $c->birthdate?->toDateString(),
            'group' => $c->group ? [
                'id' => $c->group->id, 'name' => $c->group->name,
                'price_tier' => $c->group->price_tier, 'discount_percent' => $c->group->discount_percent,
            ] : null,
            'sc_pwd' => $c->sc_pwd_id_type ? [
                'type' => $c->sc_pwd_id_type,
                'id_masked' => $c->maskedScPwdId(),
                // The full number only goes to people who can edit the record (for the form).
                ...($canEdit ? ['id_no' => $c->sc_pwd_id_no] : []),
            ] : null,
            'allow_credit' => $c->allow_credit,
            'credit_limit' => $c->credit_limit,
            'balance' => $c->balance,
            'available_credit' => $c->allow_credit ? (string) $limit->minus($owed)->max('0') : null,
            'loyalty_points' => $c->loyalty_points,
            'notes' => $c->notes,
            'is_active' => $c->is_active,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
