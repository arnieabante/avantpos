<?php

namespace App\Services\Inventory;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\Concerns\PostsLines;
use App\Services\Numbering\DocumentNumberService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock adjustments and opening stock. A draft can be edited; posting
 * writes the movements and is final (correct with a new adjustment).
 */
class StockAdjustmentService
{
    use PostsLines;

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{reason_code: string, notes?: string|null, items: list<array<string, mixed>>}  $data
     */
    public function create(Branch $branch, array $data, User $actor, bool $post = false): StockAdjustment
    {
        return DB::transaction(function () use ($branch, $data, $actor, $post) {
            $adjustment = new StockAdjustment;
            $adjustment->forceFill([
                'branch_id' => $branch->getKey(),
                'number' => $this->numbers->next(DocumentType::StockAdjustment),
                'reason_code' => $data['reason_code'],
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $actor->getKey(),
            ])->save();

            $this->writeItems($adjustment, $data['items']);
            $this->audit->log('stock_adjustment.created', $adjustment, [], ['number' => $adjustment->number, 'reason_code' => $adjustment->reason_code]);

            if ($post) {
                $this->postLocked($adjustment, $actor);
            }

            return $adjustment;
        });
    }

    /**
     * @param  array{reason_code: string, notes?: string|null, items: list<array<string, mixed>>}  $data
     */
    public function update(StockAdjustment $adjustment, array $data, User $actor, bool $post = false): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $data, $actor, $post) {
            $adjustment = $this->lockDraft($adjustment);
            $adjustment->forceFill(['reason_code' => $data['reason_code'], 'notes' => $data['notes'] ?? null])->save();

            $adjustment->items()->delete();
            $this->writeItems($adjustment, $data['items']);

            if ($post) {
                $this->postLocked($adjustment, $actor);
            }

            return $adjustment;
        });
    }

    public function post(StockAdjustment $adjustment, User $actor): StockAdjustment
    {
        return DB::transaction(fn () => $this->postLocked($this->lockDraft($adjustment), $actor));
    }

    public function cancel(StockAdjustment $adjustment): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment) {
            $adjustment = $this->lockDraft($adjustment);
            $adjustment->forceFill(['status' => 'cancelled'])->save();
            $this->audit->log('stock_adjustment.cancelled', $adjustment, [], ['number' => $adjustment->number]);

            return $adjustment;
        });
    }

    /**
     * Direction of a line: fixed by the reason, or the sign the user typed
     * for "correction" / "other".
     */
    public static function signedQty(string $reason, string $qty): BigDecimal
    {
        $qty = BigDecimal::of($qty);

        return match (true) {
            in_array($reason, StockAdjustment::IN_REASONS, true) => $qty->abs(),
            in_array($reason, StockAdjustment::OUT_REASONS, true) => $qty->abs()->negated(),
            default => $qty,
        };
    }

    private function postLocked(StockAdjustment $adjustment, User $actor): StockAdjustment
    {
        $adjustment->load('items');
        if ($adjustment->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one item.']);
        }

        $variants = $this->loadVariants($adjustment->items->pluck('product_variant_id'));
        $positions = $adjustment->items->pluck('id')->flip();
        $isOpening = $adjustment->reason_code === 'opening';

        if ($isOpening) {
            $this->assertNoHistory($adjustment);
        }

        $total = BigDecimal::zero();
        foreach ($this->inLockOrder($adjustment->items) as $item) {
            $variant = $variants[$item->product_variant_id];
            $qty = BigDecimal::of($item->qty);
            $field = 'items.'.$positions[$item->id].'.qty';
            $options = [
                'reference_type' => 'stock_adjustment',
                'reference_id' => $adjustment->getKey(),
                'reason' => $adjustment->notes ? mb_strimwidth($adjustment->notes, 0, 255) : null,
                'batch_no' => $item->batch_no,
            ];

            if ($qty->isPositive()) {
                $unitCost = $item->unit_cost ?? $this->ledger->currentUnitCost($adjustment->branch_id, $variant);
                $movement = $this->atLine($field, fn () => $this->ledger->receive(
                    $adjustment->branch_id, $variant, $qty, $unitCost, $isOpening ? 'opening' : 'adjustment_in',
                    $options + ['expiry_date' => $item->expiry_date?->toDateString()],
                ));
            } else {
                $movement = $this->atLine($field, fn () => $this->ledger->issue(
                    $adjustment->branch_id, $variant, $qty->abs(), 'adjustment_out', $options,
                ));
            }

            /** @var StockMovement $movement */
            $item->forceFill(['unit_cost' => $movement->unit_cost])->save();
            $total = $total->plus($movement->total_cost);
        }

        $adjustment->forceFill(['status' => 'posted', 'posted_by' => $actor->getKey(), 'posted_at' => now()])->save();

        $this->audit->log('stock_adjustment.posted', $adjustment, [], [
            'number' => $adjustment->number,
            'reason_code' => $adjustment->reason_code,
            'lines' => $adjustment->items->count(),
            'value_change' => (string) $total->toScale(2, RoundingMode::HalfUp),
        ]);

        return $adjustment;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeItems(StockAdjustment $adjustment, array $lines): void
    {
        foreach (array_values($lines) as $line) {
            $qty = self::signedQty($adjustment->reason_code, (string) $line['qty']);

            $item = new StockAdjustmentItem;
            $item->forceFill([
                'stock_adjustment_id' => $adjustment->getKey(),
                'product_variant_id' => $line['product_variant_id'],
                'qty' => (string) $qty->toScale(3, RoundingMode::HalfUp),
                // Costs and batches only matter for stock coming in.
                'unit_cost' => $qty->isPositive() ? ($line['unit_cost'] ?? null) : null,
                'batch_no' => $line['batch_no'] ?? null,
                'expiry_date' => $qty->isPositive() ? ($line['expiry_date'] ?? null) : null,
            ])->save();
        }
    }

    /**
     * Opening stock is the very first stock of an item in a branch.
     */
    private function assertNoHistory(StockAdjustment $adjustment): void
    {
        $used = StockMovement::query()
            ->where('branch_id', $adjustment->branch_id)
            ->whereIn('product_variant_id', $adjustment->items->pluck('product_variant_id'))
            ->distinct()
            ->pluck('product_variant_id');

        if ($used->isNotEmpty()) {
            $index = $adjustment->items->search(fn ($item) => $used->contains($item->product_variant_id));
            throw ValidationException::withMessages([
                "items.{$index}.product_variant_id" => 'This item already has stock history in this branch. Use a "Found" or "Correction" adjustment instead.',
            ]);
        }
    }

    private function lockDraft(StockAdjustment $adjustment): StockAdjustment
    {
        $locked = StockAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());

        if ($locked->status !== 'draft') {
            throw new DomainException("Adjustment {$locked->number} is already {$locked->status}.", 'DOCUMENT_NOT_EDITABLE', 409);
        }

        return $locked;
    }
}
