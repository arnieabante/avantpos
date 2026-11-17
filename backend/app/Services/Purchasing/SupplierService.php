<?php

namespace App\Services\Purchasing;

use App\Exceptions\DomainException;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class SupplierService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {
            $supplier = Supplier::query()->create($data);
            $this->audit->log('supplier.created', $supplier, [], ['name' => $supplier->name]);

            return $supplier;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data) {
            $supplier->fill($data)->save();
            $this->audit->logChanges('supplier.updated', $supplier);

            return $supplier;
        });
    }

    public function setActive(Supplier $supplier, bool $active): Supplier
    {
        $supplier->forceFill(['is_active' => $active])->save();
        $this->audit->logChanges($active ? 'supplier.activated' : 'supplier.deactivated', $supplier);

        return $supplier;
    }

    /**
     * Only suppliers without purchase history can be deleted.
     */
    public function delete(Supplier $supplier): void
    {
        $used = PurchaseOrder::query()->where('supplier_id', $supplier->getKey())->exists()
            || GoodsReceipt::query()->where('supplier_id', $supplier->getKey())->exists();

        if ($used) {
            throw new DomainException(
                "{$supplier->name} has purchase orders or deliveries on record. Deactivate the supplier instead.",
                'SUPPLIER_IN_USE', 409,
            );
        }

        DB::transaction(function () use ($supplier) {
            $supplier->delete();
            $this->audit->log('supplier.deleted', $supplier, ['name' => $supplier->name]);
        });
    }
}
