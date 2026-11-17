<?php

use App\Support\Database\SchemaMacros;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription lifecycle: a plan change waiting for the end of the period
 * (downgrades), renewal reminders, the kind of each invoice and the credit
 * given for unused time on an upgrade, and the review of bank-transfer
 * payments sent in by a business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('pending_plan_id')->nullable()->after('subscription_plan_id')->constrained('subscription_plans')->nullOnDelete();
            $table->string('pending_billing_cycle', 10)->nullable()->after('pending_plan_id');
            $table->timestamp('last_reminder_at')->nullable()->after('cancelled_at');
        });

        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->string('kind', 20)->default('renewal')->after('billing_cycle')->comment('new, renewal, upgrade');
            $table->money('credit')->default(0)->after('subtotal')->comment('Unused time of the previous plan (upgrades)');
            $table->text('notes')->nullable()->after('pdf_path');
        });

        Schema::table('billing_payments', function (Blueprint $table) {
            $table->tenantForeign('submitted_by', 'users', nullable: true);
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropForeign(SchemaMacros::name('billing_payments', 'submitted_by', 'fk'));
            $table->dropColumn(['submitted_by', 'reviewed_at', 'rejection_reason']);
        });
        Schema::table('subscription_invoices', fn (Blueprint $table) => $table->dropColumn(['kind', 'credit', 'notes']));
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_plan_id');
            $table->dropColumn(['pending_billing_cycle', 'last_reminder_at']);
        });
    }
};
