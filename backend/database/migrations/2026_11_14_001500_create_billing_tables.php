<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS subscription billing (AvantPOS charging its tenants, via Xendit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_cycle', 10)->default('monthly')->comment('monthly, annual');
            $table->string('status', 20)->comment('trialing, active, past_due, grace, expired, cancelled');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'current_period_end']);
        });

        Schema::create('subscription_history', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('subscription_id', 'subscriptions');
            $table->foreignId('from_plan_id')->nullable()->constrained('subscription_plans');
            $table->foreignId('to_plan_id')->nullable()->constrained('subscription_plans');
            $table->string('change_type', 20)->comment('created, upgrade, downgrade, renew, cancel, expire, reactivate');
            $table->money('prorated_amount')->default(0);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 30)->nullable();
            $table->timestamp('effective_at');
            $table->timestamps();
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('subscription_id', 'subscriptions');
            $table->string('number', 30)->unique();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_cycle', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->money('subtotal');
            $table->money('vat_amount')->default(0);
            $table->money('total');
            $table->string('status', 20)->default('open')->comment('open, paid, void, expired');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('gateway', 20)->nullable()->comment('xendit, manual');
            $table->string('gateway_invoice_id', 100)->nullable();
            $table->string('gateway_checkout_url', 500)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });

        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('subscription_invoice_id', 'subscription_invoices');
            $table->string('gateway', 20)->comment('xendit, manual_bank_transfer');
            $table->string('gateway_reference', 120)->nullable();
            $table->string('channel', 40)->nullable()->comment('GCASH, PAYMAYA, CARD, BPI...');
            $table->money('amount');
            $table->string('status', 20)->comment('pending, paid, failed, refunded');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('platform_admins');
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('event_id', 120);
            $table->string('event_type', 80)->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('received')->comment('received, processed, failed, ignored');
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('subscription_history');
        Schema::dropIfExists('subscriptions');
    }
};
