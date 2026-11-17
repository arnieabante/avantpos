<?php

use App\Enums\DocumentType;
use App\Services\Numbering\DocumentNumberService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ['tenant' => $this->tenant] = $this->createTenant();
    $this->terminal = $this->createTerminal($this->tenant);
    $this->numbers = app(DocumentNumberService::class);
});

it('issues sequential, zero-padded numbers per terminal', function () {
    $this->asTenant($this->tenant, function () {
        $other = $this->createTerminal($this->tenant);

        $issued = DB::transaction(fn () => [
            $this->numbers->next(DocumentType::SalesInvoice, $this->terminal->id),
            $this->numbers->next(DocumentType::SalesInvoice, $this->terminal->id),
            $this->numbers->next(DocumentType::SalesInvoice, $other->id),
            $this->numbers->next(DocumentType::PurchaseOrder),
        ]);

        expect($issued)->toBe(['000000001', '000000002', '000000001', 'PO-000000001']);
    });
});

it('does not consume a number when the transaction rolls back (gapless)', function () {
    $this->asTenant($this->tenant, function () {
        try {
            DB::transaction(function () {
                $this->numbers->next(DocumentType::SalesInvoice, $this->terminal->id);
                throw new RuntimeException('sale failed');
            });
        } catch (RuntimeException) {
        }

        $next = DB::transaction(fn () => $this->numbers->next(DocumentType::SalesInvoice, $this->terminal->id));
        expect($next)->toBe('000000001');
    });
});

it('moves forward (never back) after offline numbers are synced', function () {
    $this->asTenant($this->tenant, function () {
        DB::transaction(fn () => $this->numbers->advanceTo(DocumentType::SalesInvoice, $this->terminal->id, 41));
        DB::transaction(fn () => $this->numbers->advanceTo(DocumentType::SalesInvoice, $this->terminal->id, 7));

        expect(DB::transaction(fn () => $this->numbers->next(DocumentType::SalesInvoice, $this->terminal->id)))->toBe('000000042');
    });
});

it('keeps series separate per tenant', function () {
    ['tenant' => $other] = $this->createTenant();
    $otherTerminal = $this->createTerminal($other);

    $a = $this->asTenant($this->tenant, fn () => DB::transaction(fn () => $this->numbers->next(DocumentType::PurchaseOrder)));
    $b = $this->asTenant($other, fn () => DB::transaction(fn () => $this->numbers->next(DocumentType::PurchaseOrder)));

    expect($a)->toBe('PO-000000001')->and($b)->toBe('PO-000000001');
});
