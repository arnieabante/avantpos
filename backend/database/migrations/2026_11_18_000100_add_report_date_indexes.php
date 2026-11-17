<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the date-range queries of reports, readings and statements
 * (business + branch + date), so they stay fast as years of data pile up.
 */
return new class extends Migration
{
    private const INDEXES = [
        'payments' => ['tenant_id', 'paid_at'],
        'stock_movements' => ['tenant_id', 'branch_id', 'occurred_at'],
        'refunds' => ['tenant_id', 'branch_id', 'refunded_at'],
        'goods_receipts' => ['tenant_id', 'branch_id', 'received_at'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $this->name($table, $columns)));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($this->name($table, $columns)));
        }
    }

    private function name(string $table, array $columns): string
    {
        return $table.'_'.implode('_', $columns).'_report_idx';
    }
};
