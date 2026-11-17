<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax rates, payment methods, discount presets, receipt templates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('name', 60);
            $table->string('type', 20)->comment('vat, percentage_tax');
            $table->rate('rate');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('type', 20)->comment('cash, gcash, maya, bank_transfer, card, credit, other');
            $table->string('name', 60);
            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('gateway_code', 30)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('discount_presets', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('name', 80);
            $table->string('type', 10)->comment('percent, fixed');
            $table->decimal('value', 15, 4);
            $table->string('scope', 15)->comment('line, transaction');
            $table->string('statutory', 10)->nullable()->comment('senior, pwd, naac, solo_parent, null');
            $table->boolean('requires_permission')->default(false);
            $table->money('max_amount')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('receipt_templates', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('name', 80);
            $table->string('paper', 10)->comment('58mm, 80mm, a4');
            $table->text('header_text')->nullable();
            $table->text('footer_text')->nullable();
            $table->boolean('show_logo')->default(true);
            $table->json('layout')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_templates');
        Schema::dropIfExists('discount_presets');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('tax_rates');
    }
};
