<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers, purchase orders, goods receiving and accounts payable.
 *
 * Stock increases ONLY through goods_receipts (never on PO creation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('code', 30)->nullable();
            $table->string('name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('tin', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('supplier_id', 'suppliers');
            $table->string('number', 30);
            $table->string('status', 20)->default('draft')
                ->comment('draft, approved, partially_received, received, cancelled, closed');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->money('subtotal')->default(0);
            $table->money('tax_total')->default(0);
            $table->money('total')->default(0);
            $table->text('notes')->nullable();
            $table->tenantForeign('created_by', 'users');
            $table->tenantForeign('approved_by', 'users', nullable: true);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('purchase_order_id', 'purchase_orders', onDelete: 'cascade');
            $table->tenantForeign('product_variant_id', 'product_variants');
            $table->tenantForeign('product_unit_id', 'product_units', nullable: true);
            $table->decimal('conversion_factor', 15, 4)->default(1);
            $table->quantity('qty_ordered')->comment('In purchase unit');
            $table->quantity('qty_received')->default(0)->comment('In purchase unit');
            $table->unitCost('unit_cost')->comment('Per purchase unit');
            $table->money('line_total');
            $table->timestamps();
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('purchase_order_id', 'purchase_orders', nullable: true);
            $table->tenantForeign('supplier_id', 'suppliers');
            $table->string('number', 30);
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->timestamp('received_at');
            $table->money('subtotal')->default(0);
            $table->money('tax_total')->default(0);
            $table->money('total')->default(0);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('posted')->comment('posted, reversed');
            $table->tenantForeign('received_by', 'users');
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('goods_receipt_id', 'goods_receipts', onDelete: 'cascade');
            $table->tenantForeign('purchase_order_item_id', 'purchase_order_items', nullable: true);
            $table->tenantForeign('product_variant_id', 'product_variants');
            $table->tenantForeign('product_unit_id', 'product_units', nullable: true);
            $table->decimal('conversion_factor', 15, 4)->default(1);
            $table->quantity('qty')->comment('In purchase unit');
            $table->quantity('base_qty')->comment('qty x conversion_factor');
            $table->unitCost('unit_cost')->comment('Per purchase unit');
            $table->money('line_total');
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->tenantForeign('inventory_layer_id', 'inventory_layers', nullable: true);
            $table->timestamps();
        });

        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('supplier_id', 'suppliers');
            $table->tenantForeign('goods_receipt_id', 'goods_receipts', nullable: true);
            $table->string('number', 30);
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->money('total');
            $table->money('amount_paid')->default(0);
            $table->string('status', 20)->default('open')->comment('open, partially_paid, paid, void');
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('supplier_id', 'suppliers');
            $table->tenantForeign('payment_method_id', 'payment_methods', nullable: true);
            $table->string('number', 30);
            $table->date('paid_at');
            $table->money('amount');
            $table->string('reference_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('posted')->comment('posted, void');
            $table->tenantForeign('created_by', 'users');
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('supplier_payment_id', 'supplier_payments', onDelete: 'cascade');
            $table->tenantForeign('supplier_bill_id', 'supplier_bills');
            $table->money('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_bills');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
