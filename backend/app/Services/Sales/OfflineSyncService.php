<?php

namespace App\Services\Sales;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\InventoryBalance;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SyncBatch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records sales made while the POS was offline.
 *
 * An offline sale already happened (goods left, money was taken, an invoice
 * was printed), so it is recorded as it was made and never silently dropped.
 * The server still prices it with its own rules (SaleCalculator); anything
 * that does not match is recorded on the sale as a sync issue and the sale
 * is flagged for a manager to review:
 *
 *   price_changed     the device used a price different from the server's
 *   total_mismatch    the device's total differs from the server's
 *   payment_mismatch  the tenders do not satisfy the payment rules
 *   negative_stock    stock went below zero for an item that does not allow it
 *   shift_closed      the shift was closed before the sale was uploaded
 *   invoice_gap       numbers were skipped before this invoice
 *   renumbered        the invoice number was already used; a new one was given
 *
 * Sales are processed in invoice order, each in its own transaction, and
 * are idempotent on the client UUID (a re-sent batch records nothing twice).
 */
class OfflineSyncService
{
    public function __construct(
        private readonly SaleCalculator $calculator,
        private readonly CheckoutService $checkout,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{client_batch_uuid: string, sales: list<array<string, mixed>>}  $data
     * @return array{batch_id: int, results: list<array<string, mixed>>, next_invoice_no: string|null}
     */
    public function sync(Branch $branch, array $data, User $actor): array
    {
        $sales = collect($data['sales'])->sortBy(fn ($s) => [(int) $s['cash_register_session_id'], (int) $s['invoice_no']])->values();

        $batch = SyncBatch::query()->where('client_batch_uuid', $data['client_batch_uuid'])->first();
        $terminalId = null;
        $results = [];

        foreach ($sales as $sale) {
            try {
                $result = $this->one($branch, $sale, $actor, $batch, $data['client_batch_uuid']);
                $batch = $result['batch'];
                $terminalId = $result['terminal_id'] ?? $terminalId;
                unset($result['batch'], $result['terminal_id']);
            } catch (ValidationException $e) {
                $result = ['client_uuid' => $sale['client_uuid'], 'status' => 'rejected', 'message' => collect($e->errors())->flatten()->first()];
            } catch (DomainException $e) {
                $result = ['client_uuid' => $sale['client_uuid'], 'status' => 'rejected', 'message' => $e->getMessage()];
            }
            $results[] = $result;
        }

        if ($batch) {
            $count = fn (string $status) => collect($results)->where('status', $status)->count();
            DB::table('sync_batches')->where('id', $batch->getKey())->update([
                'sales_count' => DB::raw('sales_count + '.count($results)),
                'accepted_count' => DB::raw('accepted_count + '.$count('accepted')),
                'flagged_count' => DB::raw('flagged_count + '.$count('flagged')),
                'duplicate_count' => DB::raw('duplicate_count + '.$count('duplicate')),
                'status' => 'processed',
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'batch_id' => $batch?->getKey(),
            'results' => $results,
            'next_invoice_no' => $terminalId ? $this->numbers->peek(DocumentType::SalesInvoice, $terminalId) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function one(Branch $branch, array $s, User $actor, ?SyncBatch $batch, string $batchUuid): array
    {
        if ($existing = Sale::query()->where('client_uuid', $s['client_uuid'])->first()) {
            if ($existing->cashier_id !== $actor->getKey()) {
                throw new DomainException('This sale ID was already used by another cashier.', 'IDEMPOTENCY_CONFLICT', 409);
            }

            return ['client_uuid' => $s['client_uuid'], 'status' => 'duplicate', 'sale_id' => $existing->id, 'invoice_no' => $existing->invoice_no, 'batch' => $batch];
        }

        $session = CashRegisterSession::query()->find($s['cash_register_session_id']);
        if (! $session || $session->user_id !== $actor->getKey() || $session->branch_id !== $branch->getKey()) {
            throw new DomainException('This sale was made on a shift that is not yours in this branch.', 'SHIFT_MISMATCH', 422);
        }

        $issues = [];
        $cart = [
            '_offline' => true,
            'items' => array_map(fn ($i) => [
                'product_variant_id' => $i['product_variant_id'],
                'product_unit_id' => $i['product_unit_id'] ?? null,
                'qty' => $i['qty'],
                'unit_price' => $i['unit_price'],
            ], $s['items']),
        ];
        $quote = $this->calculator->calculate($branch->getKey(), $cart);
        $total = Money::of($quote['totals']['total']);

        $changed = collect($quote['lines'])->where('price_overridden', true);
        if ($changed->isNotEmpty()) {
            $issues[] = ['code' => 'price_changed', 'message' => 'Sold at a price different from the current price.', 'lines' => $changed->map(fn ($l) => [
                'sku' => $l['variant']->sku, 'price' => (string) $l['list_price'], 'sold_at' => (string) $l['unit_price'],
            ])->values()->all()];
        }
        if (isset($s['total']) && ! Money::of($s['total'])->equals($total)) {
            $issues[] = ['code' => 'total_mismatch', 'message' => "The device total was {$s['total']}; the server computed {$total}.", 'device' => (string) Money::of($s['total']), 'server' => (string) $total];
        }

        try {
            $tenders = $this->checkout->tenders($s['payments'], $total, null);
        } catch (ValidationException $e) {
            $issues[] = ['code' => 'payment_mismatch', 'message' => collect($e->errors())->flatten()->first()];
            $tenders = $this->rawTenders($s['payments']);
        }
        if (! $session->isOpen()) {
            $issues[] = ['code' => 'shift_closed', 'message' => 'The shift was already closed when this sale was uploaded; its cash count does not include it.'];
        }

        return DB::transaction(function () use ($branch, $s, $actor, $batch, $batchUuid, $session, $quote, $tenders, $issues) {
            $batch ??= $this->batch($batchUuid, $session->terminal_id);

            // Invoice number: the device continues the terminal's series. Keep it when free, else renumber.
            $number = $this->invoiceNumber($session->terminal_id, (string) $s['invoice_no'], $issues);

            $sale = $this->checkout->record($branch, $session, ['client_uuid' => $s['client_uuid'], 'notes' => null], $quote, $tenders, [], $actor, [
                'invoice_no' => $number,
                'sold_at' => Carbon::parse($s['sold_at'])->min(now()),
                'sync_batch_id' => $batch->getKey(),
                'sync_status' => 'ok',
                'pricing_version' => $s['pricing_version'] ?? null,
            ]);

            // Stock that went below zero where the product does not allow it.
            $negative = collect($quote['lines'])
                ->filter(fn ($l) => $l['product']->type === 'standard' && $l['product']->track_inventory && ! $l['product']->allow_negative_stock)
                ->filter(fn ($l) => BigDecimal::of(InventoryBalance::query()->where('branch_id', $branch->getKey())
                    ->where('product_variant_id', $l['variant']->getKey())->value('qty_on_hand') ?? '0')->isNegative())
                ->map(fn ($l) => $l['variant']->sku)->values()->all();
            if ($negative !== []) {
                $issues[] = ['code' => 'negative_stock', 'message' => 'Stock went below zero: count these items.', 'skus' => $negative];
            }

            $sale->forceFill(['sync_status' => $issues ? 'flagged' : 'ok', 'sync_issues' => $issues ?: null])->save();
            if ($issues) {
                $this->audit->log('sale.offline_flagged', $sale, meta: ['invoice_no' => $sale->invoice_no, 'issues' => array_column($issues, 'code')]);
            }

            return [
                'client_uuid' => $s['client_uuid'],
                'status' => $issues ? 'flagged' : 'accepted',
                'sale_id' => $sale->id,
                'invoice_no' => $sale->invoice_no,
                'issues' => array_column($issues, 'code'),
                'batch' => $batch,
                'terminal_id' => $session->terminal_id,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function invoiceNumber(int $terminalId, string $proposed, array &$issues): string
    {
        $taken = ! ctype_digit($proposed) || Sale::query()->where('terminal_id', $terminalId)->where('invoice_no', $proposed)->exists();
        if ($taken) {
            $number = $this->numbers->next(DocumentType::SalesInvoice, $terminalId);
            $issues[] = ['code' => 'renumbered', 'message' => "Invoice {$proposed} was already used on this terminal; recorded as {$number}.", 'printed' => $proposed, 'recorded' => $number];

            return $number;
        }

        $expected = (int) $this->numbers->peek(DocumentType::SalesInvoice, $terminalId);
        if ((int) $proposed > $expected) {
            $issues[] = ['code' => 'invoice_gap', 'message' => 'Invoice numbers '.$expected.' to '.((int) $proposed - 1).' were not received.'];
        }
        $this->numbers->advanceTo(DocumentType::SalesInvoice, $terminalId, (int) $proposed);

        return $proposed;
    }

    private function batch(string $uuid, int $terminalId): SyncBatch
    {
        $batch = new SyncBatch;
        $batch->forceFill([
            'terminal_id' => $terminalId,
            'client_batch_uuid' => $uuid,
            'status' => 'received',
            'received_at' => now(),
        ])->save();

        return $batch;
    }

    /**
     * Payments exactly as entered on the device (used when they do not pass the
     * usual rules; the sale is flagged).
     *
     * @param  list<array<string, mixed>>  $payments
     * @return array{rows: list<array<string, mixed>>, paid: Money, change: Money, credit: Money}
     */
    private function rawTenders(array $payments): array
    {
        $methods = PaymentMethod::query()->whereIn('id', array_column($payments, 'payment_method_id'))->get()->keyBy('id');
        $rows = [];
        foreach ($payments as $i => $p) {
            $method = $methods[$p['payment_method_id']] ?? null;
            if (! $method || $method->type === 'credit') {
                throw ValidationException::withMessages(["payments.{$i}.payment_method_id" => 'This payment method cannot be used offline.']);
            }
            $rows[] = ['method' => $method, 'amount' => Money::of($p['amount']), 'tendered' => null, 'change' => Money::zero(), 'reference_no' => $p['reference_no'] ?? null];
        }
        $paid = Money::sum(array_column($rows, 'amount'));

        return ['rows' => $rows, 'paid' => $paid, 'change' => Money::zero(), 'credit' => Money::zero()];
    }

    /**
     * A manager marks a flagged offline sale as reviewed.
     */
    public function review(Sale $sale, string $notes, User $actor): Sale
    {
        if ($sale->sync_status !== 'flagged') {
            throw new DomainException('This sale has nothing to review.', 'NOTHING_TO_REVIEW', 409);
        }
        $issues = $sale->sync_issues ?? [];
        $issues[] = ['code' => 'reviewed', 'by' => $actor->getKey(), 'at' => now()->toIso8601String(), 'notes' => $notes];
        $sale->forceFill(['sync_status' => 'reviewed', 'sync_issues' => $issues])->save();
        $this->audit->log('sale.sync_reviewed', $sale, ['sync_status' => 'flagged'], ['sync_status' => 'reviewed'], ['notes' => $notes]);

        return $sale;
    }
}
