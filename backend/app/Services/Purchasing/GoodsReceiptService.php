<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryLayer;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\Concerns\PostsLines;
use App\Services\Inventory\StockLedger;
use App\Services\Numbering\DocumentNumberService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods receiving: the only way purchases enter stock.
 *
 * Each line becomes one FIFO cost layer at the cost per BASE unit, net of
 * claimable input VAT. The receipt remembers that layer so a wrong receipt
 * can be reversed exactly — but only while its stock is still untouched.
 */
class GoodsReceiptService
{
    use PostsLines;

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly PurchaseTax $tax,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function receive(Branch $branch, array $data, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($branch, $data, $actor) {
            $po = isset($data['purchase_order_id'])
                ? PurchaseOrder::query()->lockForUpdate()->findOrFail($data['purchase_order_id'])
                : null;

            if ($po) {
                $this->assertReceivable($po, $branch);
            }

            $supplier = $po ? Supplier::withTrashed()->findOrFail($po->supplier_id) : Supplier::query()->find($data['supplier_id'] ?? 0);
            if (! $supplier || (! $po && ! $supplier->is_active)) {
                throw ValidationException::withMessages(['supplier_id' => 'Choose an active supplier.']);
            }

            $receipt = new GoodsReceipt;
            $receipt->forceFill([
                'branch_id' => $branch->getKey(),
                'purchase_order_id' => $po?->getKey(),
                'supplier_id' => $supplier->getKey(),
                'number' => $this->numbers->next(DocumentType::GoodsReceipt),
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
                'received_at' => now(),
                'notes' => $data['notes'] ?? null,
                'status' => 'posted',
                'received_by' => $actor->getKey(),
            ])->save();

            $lines = $this->resolveLines($po, $data['items']);
            $total = BigDecimal::zero();
            $tax = BigDecimal::zero();
            $costChanges = [];

            foreach ($this->inLockOrder($lines) as $line) {
                $field = "items.{$line->id}.qty";
                $variant = $line->variant;
                $amounts = $this->tax->line((string) $line->qty, (string) $line->unit_cost, $supplier, $variant->product);
                $baseQty = $line->qty->multipliedBy($line->factor)->toScale(3, RoundingMode::HalfUp);
                $costPerBase = $amounts['total']->minus($amounts['tax'])->dividedBy($baseQty, 4, RoundingMode::HalfUp);

                $movement = $this->atLine($field, fn () => $this->ledger->receive(
                    $branch->getKey(), $variant, $baseQty, $costPerBase, 'purchase_receipt', [
                        'reference_type' => 'goods_receipt',
                        'reference_id' => $receipt->getKey(),
                        'reason' => "Received from {$supplier->name}",
                        'batch_no' => $line->batch_no,
                        'expiry_date' => $line->expiry_date,
                    ],
                ));

                $item = new GoodsReceiptItem;
                $item->forceFill([
                    'goods_receipt_id' => $receipt->getKey(),
                    'purchase_order_item_id' => $line->po_item?->getKey(),
                    'product_variant_id' => $variant->getKey(),
                    'product_unit_id' => $line->unit?->getKey(),
                    'conversion_factor' => (string) $line->factor->toScale(4),
                    'qty' => (string) $line->qty,
                    'base_qty' => (string) $baseQty,
                    'unit_cost' => (string) $line->unit_cost,
                    'line_total' => (string) $amounts['total'],
                    'tax_amount' => (string) $amounts['tax'],
                    'batch_no' => $line->batch_no,
                    'expiry_date' => $line->expiry_date,
                    'inventory_layer_id' => $movement->getRelation('createdLayer')->getKey(),
                ])->save();

                if ($line->po_item) {
                    $line->po_item->forceFill(['qty_received' => (string) BigDecimal::of($line->po_item->qty_received)->plus($line->qty)->toScale(3)])->save();
                }

                // The product's reference cost becomes the latest purchase cost (per base unit, net of input VAT).
                if ($variant->cost_price === null || ! BigDecimal::of($variant->cost_price)->isEqualTo($costPerBase)) {
                    $costChanges[$variant->getKey()] = ['from' => $variant->cost_price, 'to' => (string) $costPerBase];
                    $variant->forceFill(['cost_price' => (string) $costPerBase])->save();
                }

                $total = $total->plus($amounts['total']);
                $tax = $tax->plus($amounts['tax']);
            }

            $receipt->forceFill([
                'subtotal' => (string) $total->minus($tax)->toScale(2),
                'tax_total' => (string) $tax->toScale(2),
                'total' => (string) $total->toScale(2),
            ])->save();

            if ($po) {
                PurchaseOrderService::refreshStatus($po);
            }

            $this->audit->log('goods_receipt.posted', $receipt, [], array_filter([
                'number' => $receipt->number,
                'purchase_order' => $po?->number,
                'supplier' => $supplier->name,
                'total' => $receipt->total,
                'cost_changes' => $costChanges ?: null,
            ]));

            return $receipt;
        });
    }

    /**
     * Undo a receipt whose stock has not been sold, moved or adjusted.
     */
    public function reverse(GoodsReceipt $receipt, User $actor, string $reason): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actor, $reason) {
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->getKey());
            if ($receipt->status !== 'posted') {
                throw new DomainException("Receipt {$receipt->number} is already {$receipt->status}.", 'DOCUMENT_NOT_EDITABLE', 409);
            }

            // A bill already paid (even partly) keeps the receipt; an unpaid bill is voided with it.
            $bill = SupplierBill::query()->lockForUpdate()->where('goods_receipt_id', $receipt->getKey())->where('status', '!=', 'void')->first();
            if ($bill && BigDecimal::of($bill->amount_paid)->isPositive()) {
                throw new DomainException("Bill {$bill->number} for this delivery is already (partly) paid, so the receipt cannot be reversed.", 'RECEIPT_BILLED', 409);
            }
            $bill?->forceFill(['status' => 'void'])->save();

            $items = $receipt->items()->get();
            $variants = $this->loadVariants($items->pluck('product_variant_id'));

            foreach ($this->inLockOrder($items) as $item) {
                $layer = InventoryLayer::query()->lockForUpdate()->find($item->inventory_layer_id);
                if (! $layer || ! BigDecimal::of($layer->qty_remaining)->isEqualTo($item->base_qty)) {
                    $name = $variants[$item->product_variant_id]->product->name;
                    throw new DomainException(
                        "Some of the {$name} from this delivery has already been sold, moved or adjusted, so the receipt cannot be reversed. Correct the stock with an adjustment instead.",
                        'RECEIPT_STOCK_USED', 409, ['product_variant_id' => $item->product_variant_id],
                    );
                }

                $this->ledger->issue($receipt->branch_id, $variants[$item->product_variant_id], $item->base_qty, 'receipt_reversal', [
                    'layer_id' => $layer->getKey(),
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $receipt->getKey(),
                    'reason' => mb_strimwidth("Receipt {$receipt->number} reversed: {$reason}", 0, 255),
                ]);

                if ($item->purchase_order_item_id) {
                    $poItem = PurchaseOrderItem::query()->lockForUpdate()->findOrFail($item->purchase_order_item_id);
                    $poItem->forceFill(['qty_received' => (string) BigDecimal::of($poItem->qty_received)->minus($item->qty)->toScale(3)])->save();
                }
            }

            $receipt->forceFill([
                'status' => 'reversed',
                'reversed_by' => $actor->getKey(),
                'reversed_at' => now(),
                'reversal_reason' => mb_strimwidth($reason, 0, 255),
            ])->save();

            if ($receipt->purchase_order_id) {
                PurchaseOrderService::refreshStatus(PurchaseOrder::query()->lockForUpdate()->findOrFail($receipt->purchase_order_id));
            }

            $this->audit->log('goods_receipt.reversed', $receipt, ['status' => 'posted'], ['status' => 'reversed'], [
                'number' => $receipt->number, 'reason' => $reason, 'total' => $receipt->total,
            ]);

            return $receipt;
        });
    }

    private function assertReceivable(PurchaseOrder $po, Branch $branch): void
    {
        if (! in_array($po->status, PurchaseOrder::RECEIVABLE, true)) {
            $state = str_replace('_', ' ', $po->status);
            throw new DomainException(
                $po->status === 'draft' ? "Purchase order {$po->number} must be approved before goods are received." : "Purchase order {$po->number} is {$state}.",
                'PO_NOT_RECEIVABLE', 409,
            );
        }
        if ($po->branch_id !== $branch->getKey()) {
            $name = Branch::query()->whereKey($po->branch_id)->value('name');
            throw ValidationException::withMessages(['purchase_order_id' => "This order is for {$name}. Switch to that branch to receive it."]);
        }
    }

    /**
     * Normalise the posted lines: PO lines take item, unit and factor from the
     * PO; direct lines from the request. Quantity limits are checked here.
     *
     * @param  list<array<string, mixed>>  $input
     * @return Collection<int, object>
     */
    private function resolveLines(?PurchaseOrder $po, array $input): Collection
    {
        $poItems = $po ? PurchaseOrderItem::query()->with('productUnit')->where('purchase_order_id', $po->getKey())->lockForUpdate()->get()->keyBy('id') : collect();
        $variantIds = $po ? $poItems->pluck('product_variant_id') : collect($input)->pluck('product_variant_id');
        $variants = ProductVariant::query()->with('product.baseUnit')->whereIn('id', $variantIds)->get()->keyBy('id');
        $lines = collect();

        foreach (array_values($input) as $i => $row) {
            $qty = BigDecimal::of($row['qty'])->toScale(3, RoundingMode::HalfUp);

            if ($po) {
                $poItem = $poItems[$row['purchase_order_item_id'] ?? 0] ?? null;
                if (! $poItem) {
                    throw ValidationException::withMessages(["items.{$i}.purchase_order_item_id" => 'This item is not on the purchase order.']);
                }
                $left = BigDecimal::of($poItem->qty_ordered)->minus($poItem->qty_received);
                if ($qty->isGreaterThan($left)) {
                    throw ValidationException::withMessages(["items.{$i}.qty" => 'Only '.self::trim($left).' left to receive on this order.']);
                }
                $variant = $variants[$poItem->product_variant_id];
                $unit = $poItem->productUnit;
                $factor = BigDecimal::of($poItem->conversion_factor);
            } else {
                $poItem = null;
                $variant = $variants[$row['product_variant_id']];
                ['unit' => $unit, 'factor' => $factor] = PurchaseOrderService::purchaseUnit($variant, $row['product_unit_id'] ?? null, "items.{$i}.product_unit_id");
            }

            $lines->push((object) [
                'id' => $i,
                'product_variant_id' => $variant->getKey(),
                'variant' => $variant,
                'po_item' => $poItem,
                'unit' => $unit,
                'factor' => $factor,
                'qty' => $qty,
                'unit_cost' => BigDecimal::of($row['unit_cost'] ?? $poItem?->unit_cost ?? '0')->toScale(4, RoundingMode::HalfUp),
                'batch_no' => $row['batch_no'] ?? null,
                'expiry_date' => $row['expiry_date'] ?? null,
            ]);
        }

        return $lines;
    }

    private static function trim(BigDecimal $qty): string
    {
        return rtrim(rtrim((string) $qty->toScale(3), '0'), '.') ?: '0';
    }
}
