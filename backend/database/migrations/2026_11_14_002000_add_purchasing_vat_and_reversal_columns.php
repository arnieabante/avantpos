<?php

use App\Support\Database\SchemaMacros;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4E additions:
 *  - suppliers.vat_registered: input VAT is only claimable from VAT-registered suppliers.
 *  - tax_amount per PO / receipt line: the input VAT inside the line total.
 *  - who reversed a goods receipt, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('vat_registered')->default(true)->after('tin');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->money('tax_amount')->default(0)->after('line_total');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->money('tax_amount')->default(0)->after('line_total');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->tenantForeign('reversed_by', 'users', nullable: true);
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(SchemaMacros::name('goods_receipts', 'reversed_by', 'fk'));
            $table->dropColumn(['reversed_by', 'reversed_at', 'reversal_reason']);
        });
        Schema::table('goods_receipt_items', fn (Blueprint $table) => $table->dropColumn('tax_amount'));
        Schema::table('purchase_order_items', fn (Blueprint $table) => $table->dropColumn('tax_amount'));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('vat_registered'));
    }
};
