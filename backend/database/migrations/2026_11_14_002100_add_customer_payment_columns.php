<?php

use App\Support\Database\SchemaMacros;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4F: payments on customer accounts ("utang" collections) are ledger
 * entries with a receipt number, payment method, reference and branch.
 * Loyalty adjustments carry a note explaining why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->string('number', 30)->nullable()->after('type')->comment('Collection receipt no. (payments)');
            $table->tenantForeign('payment_method_id', 'payment_methods', nullable: true);
            $table->string('reference_no', 100)->nullable();
            $table->tenantForeign('branch_id', 'branches', nullable: true);
        });

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->decimal('balance_after', 15, 2)->default(0)->after('points');
            $table->string('notes', 255)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', fn (Blueprint $table) => $table->dropColumn(['balance_after', 'notes']));
        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->dropForeign(SchemaMacros::name('customer_ledger_entries', 'payment_method_id', 'fk'));
            $table->dropForeign(SchemaMacros::name('customer_ledger_entries', 'branch_id', 'fk'));
            $table->dropColumn(['number', 'payment_method_id', 'reference_no', 'branch_id']);
        });
    }
};
