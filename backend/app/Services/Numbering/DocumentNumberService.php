<?php

namespace App\Services\Numbering;

use App\Enums\DocumentScope;
use App\Enums\DocumentType;
use App\Models\DocumentSequence;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issues gapless, non-resettable document numbers.
 *
 * MUST be called inside the same DB transaction that creates the document:
 * the sequence row stays locked (SELECT ... FOR UPDATE) until commit, and a
 * rollback releases the number again — so no gaps and no duplicates, even
 * with concurrent requests on the same series.
 */
class DocumentNumberService
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Allocate the next number of a series.
     *
     * @param  int  $scopeId  Terminal/branch id for scoped series; ignored for tenant-wide series.
     */
    public function next(DocumentType $type, int $scopeId = 0): string
    {
        $this->assertInTransaction();

        $sequence = $this->lockSequence($type, $scopeId);

        $number = $sequence->next_number;
        $sequence->next_number = $number + 1;
        $sequence->save();

        return $this->format($sequence, $number);
    }

    /**
     * Record numbers issued OFFLINE by a terminal: moves the series forward
     * so the next online number continues after the highest one used.
     * Never moves backwards.
     */
    public function advanceTo(DocumentType $type, int $scopeId, int $lastUsedNumber): void
    {
        $this->assertInTransaction();

        $sequence = $this->lockSequence($type, $scopeId);

        if ($lastUsedNumber + 1 > $sequence->next_number) {
            $sequence->next_number = $lastUsedNumber + 1;
            $sequence->save();
        }
    }

    /**
     * Next number that would be issued (no allocation, no lock).
     */
    public function peek(DocumentType $type, int $scopeId = 0): string
    {
        $sequence = $this->query($type, $scopeId)->first() ?? $this->newSequence($type, $scopeId);

        return $this->format($sequence, $sequence->next_number);
    }

    public function format(DocumentSequence $sequence, int $number): string
    {
        return $sequence->prefix.str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT);
    }

    private function lockSequence(DocumentType $type, int $scopeId): DocumentSequence
    {
        $sequence = $this->query($type, $scopeId)->lockForUpdate()->first();

        if ($sequence) {
            return $sequence;
        }

        // First use of the series: create it. insertOrIgnore makes concurrent
        // first-use safe (unique key), then we lock the winning row.
        $fresh = $this->newSequence($type, $scopeId);
        DocumentSequence::query()->insertOrIgnore([
            'tenant_id' => $this->tenant->requireId(),
            'scope_type' => $fresh->scope_type,
            'scope_id' => $fresh->scope_id,
            'doc_type' => $fresh->doc_type,
            'prefix' => $fresh->prefix,
            'padding' => $fresh->padding,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->query($type, $scopeId)->lockForUpdate()->firstOrFail();
    }

    private function query(DocumentType $type, int $scopeId)
    {
        $scope = $type->scope();

        return DocumentSequence::query()
            ->where('scope_type', $scope->value)
            ->where('scope_id', $scope === DocumentScope::Tenant ? 0 : $scopeId)
            ->where('doc_type', $type->value);
    }

    private function newSequence(DocumentType $type, int $scopeId): DocumentSequence
    {
        $scope = $type->scope();

        if ($scope !== DocumentScope::Tenant && $scopeId <= 0) {
            throw new LogicException("Document type [{$type->value}] requires a {$scope->value} scope id.");
        }

        $sequence = new DocumentSequence;
        $sequence->forceFill([
            'scope_type' => $scope->value,
            'scope_id' => $scope === DocumentScope::Tenant ? 0 : $scopeId,
            'doc_type' => $type->value,
            'prefix' => $type->defaultPrefix(),
            'padding' => (int) config('avantpos.app.document_number_padding', 9),
            'next_number' => 1,
        ]);

        return $sequence;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Document numbers must be allocated inside a database transaction.');
        }
    }
}
