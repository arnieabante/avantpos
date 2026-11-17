<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory: FIFO cost layers, append-only stock ledger, cached balances,
 * adjustments, transfers and physical counts.
 *
 * - stock_movements is the source of truth and is NEVER updated/deleted.
 * - inventory_balances is a cache, updated in the same DB transaction
 *   (row-locked) and rebuildable from the ledger.
 * - inventory_layers are FIFO cost layers (FEFO when the product tracks
 *   expiry). stock_movement_layers records exactly which layers each
 *   outbound movement consumed -> exact COGS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('product_variant_id', 'product_variants');
            $table->quantity('qty_on_hand')->default(0);
            $table->money('total_cost')->default(0)->comment('Value of remaining FIFO layers');
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'product_variant_id']);
        });

        Schema::create('inventory_layers', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('product_variant_id', 'product_variants');
            $table->string('source_type', 30)->comment('opening, purchase_receipt, return, transfer_in, adjustment_in, count_variance');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->unitCost('unit_cost');
            $table->quantity('qty_received');
            $table->quantity('qty_remaining');
            $table->timestamp('received_at');
            $table->timestamps();
            // FIFO / FEFO lookup of open layers
            $table->index(['branch_id', 'product_variant_id', 'qty_remaining', 'expiry_date', 'received_at'], 'inventory_layers_consumption_index');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('product_variant_id', 'product_variants');
            $table->string('type', 30)->comment('sale, sale_void, return, purchase_receipt, adjustment_in, adjustment_out, transfer_out, transfer_in, count_variance, opening');
            $table->quantity('qty')->comment('Signed, base units');
            $table->unitCost('unit_cost')->comment('Average cost of this movement (from layers)');
            $table->money('total_cost')->comment('Signed');
            $table->quantity('balance_after');
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->tenantForeign('user_id', 'users', nullable: true);
            $table->string('reason', 255)->nullable();
            $table->timestamp('occurred_at')->comment('Business time (device time for offline sales)');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'branch_id', 'product_variant_id', 'occurred_at'], 'stock_movements_ledger_index');
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('stock_movement_layers', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('stock_movement_id', 'stock_movements', onDelete: 'cascade');
            $table->tenantForeign('inventory_layer_id', 'inventory_layers');
            $table->quantity('qty')->comment('Positive = consumed from layer, negative = restored');
            $table->unitCost('unit_cost');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->string('number', 30);
            $table->string('reason_code', 30)->comment('damaged, expired, lost, found, correction, internal_use, other');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft')->comment('draft, posted, cancelled');
            $table->tenantForeign('created_by', 'users');
            $table->tenantForeign('posted_by', 'users', nullable: true);
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('stock_adjustment_id', 'stock_adjustments', onDelete: 'cascade');
            $table->tenantForeign('product_variant_id', 'product_variants');
            $table->quantity('qty')->comment('Signed, base units');
            $table->unitCost('unit_cost')->nullable()->comment('Required for positive adjustments');
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('from_branch_id', 'branches');
            $table->tenantForeign('to_branch_id', 'branches');
            $table->string('number', 30);
            $table->string('status', 20)->default('draft')->comment('draft, in_transit, received, cancelled');
            $table->text('notes')->nullable();
            $table->tenantForeign('created_by', 'users');
            $table->tenantForeign('dispatched_by', 'users', nullable: true);
            $table->tenantForeign('received_by', 'users', nullable: true);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('stock_transfer_id', 'stock_transfers', onDelete: 'cascade');
            $table->tenantForeign('product_variant_id', 'product_variants');
            $table->quantity('qty_sent');
            $table->quantity('qty_received')->default(0);
            $table->money('total_cost')->default(0)->comment('FIFO cost carried to destination');
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->string('number', 30);
            $table->string('status', 20)->default('draft')->comment('draft, counting, finalized, cancelled');
            $table->text('notes')->nullable();
            $table->tenantForeign('created_by', 'users');
            $table->tenantForeign('finalized_by', 'users', nullable: true);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('stock_count_id', 'stock_counts', onDelete: 'cascade');
            $table->tenantForeign('product_variant_id', 'product_variants');
            $table->quantity('system_qty');
            $table->quantity('counted_qty')->nullable();
            $table->quantity('variance_qty')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_movement_layers');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_layers');
        Schema::dropIfExists('inventory_balances');
    }
};
