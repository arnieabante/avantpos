<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumberService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders. Totals are always computed here from quantities and
 * unit costs; nothing the client sends as a total is trusted.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly PurchaseTax $tax,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Branch $branch, array $data, User $actor, bool $approve = false): PurchaseOrder
    {
        $supplier = $this->activeSupplier((int) $data['supplier_id']);

        return DB::transaction(function () use ($branch, $data, $actor, $approve, $supplier) {
            $po = new PurchaseOrder;
            $po->forceFill([
                'branch_id' => $branch->getKey(),
                'supplier_id' => $supplier->getKey(),
                'number' => $this->numbers->next(DocumentType::PurchaseOrder),
                'status' => 'draft',
                'order_date' => $data['order_date'] ?? today()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->writeItems($po, $supplier, $data['items']);
            $this->audit->log('purchase_order.created', $po, [], ['number' => $po->number, 'total' => $po->total]);

            return $approve ? $this->approveLocked($po, $actor) : $po;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseOrder $po, array $data, User $actor, bool $approve = false): PurchaseOrder
    {
        $supplier = $this->activeSupplier((int) $data['supplier_id']);

        return DB::transaction(function () use ($po, $data, $actor, $approve, $supplier) {
            $po = $this->lock($po, 'draft');
            $po->forceFill([
                'supplier_id' => $supplier->getKey(),
                'order_date' => $data['order_date'] ?? $po->order_date,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ])->save();
            $po->items()->delete();
            $this->writeItems($po, $supplier, $data['items']);

            return $approve ? $this->approveLocked($po, $actor) : $po;
        });
    }

    public function approve(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return DB::transaction(fn () => $this->approveLocked($this->lock($po, 'draft'), $actor));
    }

    /**
     * Cancel a draft or an approved PO on which nothing was received.
     */
    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po) {
            $po = $this->lock($po, 'draft', 'approved');
            $previous = $po->status;
            $po->forceFill(['status' => 'cancelled'])->save();
            $this->audit->log('purchase_order.cancelled', $po, ['status' => $previous], ['status' => 'cancelled'], ['number' => $po->number]);

            return $po;
        });
    }

    /**
     * Stop expecting the rest of a partially received PO.
     */
    public function close(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po) {
            $po = $this->lock($po, 'partially_received');
            $po->forceFill(['status' => 'closed'])->save();
            $this->audit->log('purchase_order.closed', $po, ['status' => 'partially_received'], ['status' => 'closed'], ['number' => $po->number]);

            return $po;
        });
    }

    /**
     * Status from what was received so far (closed / cancelled stay as they are).
     */
    public static function refreshStatus(PurchaseOrder $po): void
    {
        if (in_array($po->status, ['draft', 'cancelled', 'closed'], true)) {
            return;
        }

        $items = $po->items()->get();
        $anyReceived = $items->contains(fn ($i) => BigDecimal::of($i->qty_received)->isPositive());
        $allReceived = $items->every(fn ($i) => BigDecimal::of($i->qty_received)->isGreaterThanOrEqualTo($i->qty_ordered));

        $po->forceFill(['status' => $allReceived ? 'received' : ($anyReceived ? 'partially_received' : 'approved')])->save();
    }

    /**
     * The purchase unit of a line: the base unit (factor 1) or one of the
     * variant's purchasable units.
     *
     * @return array{unit: ProductUnit|null, factor: BigDecimal}
     */
    public static function purchaseUnit(ProductVariant $variant, ?int $productUnitId, string $field): array
    {
        if ($productUnitId === null) {
            return ['unit' => null, 'factor' => BigDecimal::one()];
        }

        $unit = ProductUnit::query()->where('product_variant_id', $variant->getKey())->find($productUnitId);
        if (! $unit || ! $unit->is_purchasable) {
            throw ValidationException::withMessages([$field => 'This unit cannot be used to buy this item.']);
        }

        return ['unit' => $unit, 'factor' => BigDecimal::of($unit->conversion_factor)];
    }

    private function approveLocked(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if (! $actor->can('po.approve')) {
            throw new DomainException('You are not allowed to approve purchase orders.', 'FORBIDDEN', 403);
        }
        if (! $po->items()->exists()) {
            throw ValidationException::withMessages(['items' => 'Add at least one item.']);
        }

        $po->forceFill(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => now()])->save();
        $this->audit->log('purchase_order.approved', $po, ['status' => 'draft'], ['status' => 'approved'], ['number' => $po->number, 'total' => $po->total]);

        return $po;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeItems(PurchaseOrder $po, Supplier $supplier, array $lines): void
    {
        $variants = ProductVariant::query()->with('product')->whereIn('id', array_column($lines, 'product_variant_id'))->get()->keyBy('id');
        $total = BigDecimal::zero();
        $tax = BigDecimal::zero();

        foreach (array_values($lines) as $i => $line) {
            $variant = $variants[$line['product_variant_id']];
            ['unit' => $unit, 'factor' => $factor] = self::purchaseUnit($variant, $line['product_unit_id'] ?? null, "items.{$i}.product_unit_id");
            $qty = BigDecimal::of($line['qty'])->toScale(3, RoundingMode::HalfUp);
            $unitCost = BigDecimal::of($line['unit_cost'])->toScale(4, RoundingMode::HalfUp);
            $amounts = $this->tax->line((string) $qty, (string) $unitCost, $supplier, $variant->product);

            $item = new PurchaseOrderItem;
            $item->forceFill([
                'purchase_order_id' => $po->getKey(),
                'product_variant_id' => $variant->getKey(),
                'product_unit_id' => $unit?->getKey(),
                'conversion_factor' => (string) $factor->toScale(4),
                'qty_ordered' => (string) $qty,
                'qty_received' => '0',
                'unit_cost' => (string) $unitCost,
                'line_total' => (string) $amounts['total'],
                'tax_amount' => (string) $amounts['tax'],
            ])->save();

            $total = $total->plus($amounts['total']);
            $tax = $tax->plus($amounts['tax']);
        }

        $po->forceFill([
            'subtotal' => (string) $total->minus($tax)->toScale(2),
            'tax_total' => (string) $tax->toScale(2),
            'total' => (string) $total->toScale(2),
        ])->save();
    }

    private function activeSupplier(int $id): Supplier
    {
        $supplier = Supplier::query()->find($id);
        if (! $supplier || ! $supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose an active supplier.']);
        }

        return $supplier;
    }

    private function lock(PurchaseOrder $po, string ...$statuses): PurchaseOrder
    {
        $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->getKey());

        if (! in_array($locked->status, $statuses, true)) {
            throw new DomainException("Purchase order {$locked->number} is ".str_replace('_', ' ', $locked->status).'.', 'DOCUMENT_NOT_EDITABLE', 409);
        }

        return $locked;
    }
}
