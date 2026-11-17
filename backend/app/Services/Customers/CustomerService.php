<?php

namespace App\Services\Customers;

use App\Exceptions\DomainException;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Customer records. Balances and points are NOT edited here: they change
 * only through CustomerAccountService and LoyaltyService.
 */
class CustomerService
{
    /** Tables whose rows make a customer part of the business's history. */
    private const HISTORY_TABLES = ['sales', 'held_carts', 'customer_ledger_entries', 'loyalty_transactions'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            // refresh(): pick up database defaults (balance, points, credit flags).
            $customer = Customer::query()->create($data)->refresh();
            $this->audit->log('customer.created', $customer, [], $this->auditable($customer->getAttributes()), $this->idMeta($data));

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data) {
            $before = $this->auditable($customer->getAttributes());
            $idBefore = $customer->sc_pwd_id_no;
            $customer->fill($data)->save();

            $changes = $this->auditable($customer->getChanges());
            $idChanged = array_key_exists('sc_pwd_id_no', $data) && $data['sc_pwd_id_no'] !== $idBefore;
            if ($changes !== [] || $idChanged) {
                $this->audit->log('customer.updated', $customer, Arr::only($before, array_keys($changes)), $changes,
                    $idChanged ? ['sc_pwd_id_changed' => true] : []);
            }

            return $customer;
        });
    }

    public function setActive(Customer $customer, bool $active): Customer
    {
        $customer->forceFill(['is_active' => $active])->save();
        $this->audit->log($active ? 'customer.activated' : 'customer.deactivated', $customer);

        return $customer;
    }

    /**
     * Only customers with no sales, account or points history can be deleted;
     * the rest are deactivated so their history stays complete.
     */
    public function delete(Customer $customer): void
    {
        foreach (self::HISTORY_TABLES as $table) {
            if (DB::table($table)->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->getKey())->exists()) {
                throw new DomainException(
                    "{$customer->name} has sales, account or points history. Deactivate the customer instead.",
                    'CUSTOMER_IN_USE', 409,
                );
            }
        }

        DB::transaction(function () use ($customer) {
            $this->audit->log('customer.deleted', $customer, ['name' => $customer->name, 'phone' => $customer->phone]);
            // No history references it: remove the row so the phone / code can be reused.
            $customer->forceDelete();
        });
    }

    /**
     * Attributes safe to write to the audit log (never the SC/PWD ID number).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function auditable(array $attributes): array
    {
        return Arr::except($attributes, ['sc_pwd_id_no', 'updated_at', 'created_at', 'tenant_id', 'id', 'balance', 'loyalty_points']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function idMeta(array $data): array
    {
        return filled($data['sc_pwd_id_no'] ?? null) ? ['sc_pwd_id_recorded' => true] : [];
    }
}
