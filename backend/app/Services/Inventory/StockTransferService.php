<?php

namespace App\Services\Inventory;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockMovementLayer;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlanGate;
use App\Services\Inventory\Concerns\PostsLines;
use App\Services\Numbering\DocumentNumberService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Branch-to-branch transfers.
 *
 *  draft      editable, no stock moved
 *  in_transit stock left the source (transfer_out, FIFO/FEFO cost)
 *  received   stock entered the destination with the SAME cost layers
 *             (unit cost, batch, expiry) that left the source
 *  cancelled  a draft, or an in-transit transfer whose stock went back
 *
 * Quantities not received are recorded on the item (qty_sent - qty_received)
 * as transit loss; they never reach the destination's stock.
 */
class StockTransferService
{
    use PostsLines;

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly PlanGate $plan,
    ) {}

    /**
     * @param  array{to_branch_id: int, notes?: string|null, items: list<array{product_variant_id: int, qty: string}>}  $data
     */
    public function create(Branch $from, array $data, User $actor, bool $dispatch = false): StockTransfer
    {
        $this->plan->assertFeature('stock_transfers', 'Stock transfers');
        $this->assertDestination($from->getKey(), (int) $data['to_branch_id']);

        return DB::transaction(function () use ($from, $data, $actor, $dispatch) {
            $transfer = new StockTransfer;
            $transfer->forceFill([
                'from_branch_id' => $from->getKey(),
                'to_branch_id' => $data['to_branch_id'],
                'number' => $this->numbers->next(DocumentType::StockTransfer),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->writeItems($transfer, $data['items']);
            $this->audit->log('stock_transfer.created', $transfer, [], ['number' => $transfer->number, 'to_branch_id' => $transfer->to_branch_id]);

            return $dispatch ? $this->dispatchLocked($transfer, $actor) : $transfer;
        });
    }

    /**
     * @param  array{to_branch_id: int, notes?: string|null, items: list<array{product_variant_id: int, qty: string}>}  $data
     */
    public function update(StockTransfer $transfer, array $data, User $actor, bool $dispatch = false): StockTransfer
    {
        $this->plan->assertFeature('stock_transfers', 'Stock transfers');
        $this->assertDestination($transfer->from_branch_id, (int) $data['to_branch_id']);

        return DB::transaction(function () use ($transfer, $data, $actor, $dispatch) {
            $transfer = $this->lock($transfer, 'draft');
            $transfer->forceFill(['to_branch_id' => $data['to_branch_id'], 'notes' => $data['notes'] ?? null])->save();
            $transfer->items()->delete();
            $this->writeItems($transfer, $data['items']);

            return $dispatch ? $this->dispatchLocked($transfer, $actor) : $transfer;
        });
    }

    public function dispatch(StockTransfer $transfer, User $actor): StockTransfer
    {
        $this->plan->assertFeature('stock_transfers', 'Stock transfers');

        return DB::transaction(fn () => $this->dispatchLocked($this->lock($transfer, 'draft'), $actor));
    }

    /**
     * @param  array<int, string>  $receivedQty  transfer item id => quantity received
     */
    public function receive(StockTransfer $transfer, array $receivedQty, User $actor): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $receivedQty, $actor) {
            $transfer = $this->lock($transfer, 'in_transit');
            $transfer->load('items');
            $variants = $this->loadVariants($transfer->items->pluck('product_variant_id'));
            $positions = $transfer->items->pluck('id')->flip();
            $shortages = [];

            foreach ($this->inLockOrder($transfer->items) as $item) {
                $sent = BigDecimal::of($item->qty_sent);
                $qty = BigDecimal::of($receivedQty[$item->id] ?? $item->qty_sent);
                $field = 'items.'.$positions[$item->id].'.qty_received';

                if ($qty->isNegative() || $qty->isGreaterThan($sent)) {
                    throw ValidationException::withMessages([$field => 'Enter between 0 and the quantity sent ('.$this->trim($sent).').']);
                }

                if ($qty->isPositive()) {
                    $this->atLine($field, fn () => $this->restoreLayers(
                        $transfer, $variants[$item->product_variant_id], $transfer->to_branch_id, $qty, "Transfer {$transfer->number} from {$transfer->fromBranch?->name}",
                    ));
                }

                $item->forceFill(['qty_received' => (string) $qty->toScale(3)])->save();
                if ($qty->isLessThan($sent)) {
                    $shortages[$item->product_variant_id] = (string) $sent->minus($qty)->toScale(3);
                }
            }

            $transfer->forceFill(['status' => 'received', 'received_by' => $actor->getKey(), 'received_at' => now()])->save();
            $this->audit->log('stock_transfer.received', $transfer, [], array_filter([
                'number' => $transfer->number,
                'shortages' => $shortages ?: null,
            ]));

            return $transfer;
        });
    }

    /**
     * Cancel a draft, or an in-transit transfer (the stock returns to the
     * source branch with its original cost layers).
     */
    public function cancel(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = $this->lock($transfer, 'draft', 'in_transit');

            if ($transfer->status === 'in_transit') {
                $transfer->load('items');
                $variants = $this->loadVariants($transfer->items->pluck('product_variant_id'));
                foreach ($this->inLockOrder($transfer->items) as $item) {
                    $this->restoreLayers($transfer, $variants[$item->product_variant_id], $transfer->from_branch_id,
                        BigDecimal::of($item->qty_sent), "Transfer {$transfer->number} cancelled");
                }
            }

            $previous = $transfer->status;
            $transfer->forceFill(['status' => 'cancelled'])->save();
            $this->audit->log('stock_transfer.cancelled', $transfer, ['status' => $previous], ['status' => 'cancelled'], ['number' => $transfer->number]);

            return $transfer;
        });
    }

    private function dispatchLocked(StockTransfer $transfer, User $actor): StockTransfer
    {
        $transfer->load('items');
        if ($transfer->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one item.']);
        }
        $this->assertDestination($transfer->from_branch_id, $transfer->to_branch_id);

        $variants = $this->loadVariants($transfer->items->pluck('product_variant_id'));
        $positions = $transfer->items->pluck('id')->flip();

        foreach ($this->inLockOrder($transfer->items) as $item) {
            $movement = $this->atLine('items.'.$positions[$item->id].'.qty', fn () => $this->ledger->issue(
                $transfer->from_branch_id, $variants[$item->product_variant_id], $item->qty_sent, 'transfer_out', [
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $transfer->getKey(),
                    'reason' => "Transfer {$transfer->number} to {$transfer->toBranch?->name}",
                ],
            ));
            $item->forceFill(['total_cost' => (string) BigDecimal::of($movement->total_cost)->abs()])->save();
        }

        $transfer->forceFill(['status' => 'in_transit', 'dispatched_by' => $actor->getKey(), 'dispatched_at' => now()])->save();
        $this->audit->log('stock_transfer.dispatched', $transfer, [], [
            'number' => $transfer->number,
            'value' => (string) $transfer->items->reduce(fn (BigDecimal $sum, $i) => $sum->plus($i->total_cost), BigDecimal::zero())->toScale(2),
        ]);

        return $transfer;
    }

    /**
     * Bring $qty of a dispatched item into $branchId using the layers the
     * transfer_out consumed, in the order they were consumed (FIFO/FEFO).
     */
    private function restoreLayers(StockTransfer $transfer, ProductVariant $variant, int $branchId, BigDecimal $qty, string $reason): void
    {
        $out = StockMovement::query()
            ->where('reference_type', 'stock_transfer')
            ->where('reference_id', $transfer->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->where('type', 'transfer_out')
            ->firstOrFail();

        $pieces = StockMovementLayer::query()->with('layer')->where('stock_movement_id', $out->getKey())->orderBy('id')->get();
        $options = ['reference_type' => 'stock_transfer', 'reference_id' => $transfer->getKey(), 'reason' => $reason];

        foreach ($pieces as $piece) {
            if (! $qty->isPositive()) {
                return;
            }
            $take = BigDecimal::of($piece->qty);
            $take = $take->isGreaterThan($qty) ? $qty : $take;

            $this->ledger->receive($branchId, $variant, $take, $piece->unit_cost, 'transfer_in', $options + [
                'batch_no' => $piece->layer->batch_no,
                'expiry_date' => $piece->layer->expiry_date?->toDateString(),
            ]);
            $qty = $qty->minus($take);
        }

        // Part that left the source without a layer (negative stock): use the movement's average cost.
        if ($qty->isPositive()) {
            $this->ledger->receive($branchId, $variant, $qty, $out->unit_cost, 'transfer_in', $options);
        }
    }

    /**
     * @param  list<array{product_variant_id: int, qty: string}>  $lines
     */
    private function writeItems(StockTransfer $transfer, array $lines): void
    {
        foreach (array_values($lines) as $line) {
            $item = new StockTransferItem;
            $item->forceFill([
                'stock_transfer_id' => $transfer->getKey(),
                'product_variant_id' => $line['product_variant_id'],
                'qty_sent' => (string) BigDecimal::of($line['qty'])->toScale(3, RoundingMode::HalfUp),
                'qty_received' => '0',
                'total_cost' => '0',
            ])->save();
        }
    }

    private function assertDestination(int $fromId, int $toId): void
    {
        if ($fromId === $toId) {
            throw ValidationException::withMessages(['to_branch_id' => 'Choose a different branch from the one sending the stock.']);
        }
        if (! Branch::query()->whereKey($toId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['to_branch_id' => 'The receiving branch is not active.']);
        }
    }

    private function lock(StockTransfer $transfer, string ...$statuses): StockTransfer
    {
        $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->getKey());

        if (! in_array($locked->status, $statuses, true)) {
            throw new DomainException("Transfer {$locked->number} is ".str_replace('_', ' ', $locked->status).'.', 'DOCUMENT_NOT_EDITABLE', 409);
        }

        return $locked;
    }

    private function trim(BigDecimal $qty): string
    {
        return rtrim(rtrim((string) $qty->toScale(3), '0'), '.') ?: '0';
    }
}
