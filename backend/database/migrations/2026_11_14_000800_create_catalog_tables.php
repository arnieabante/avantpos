<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product catalogue.
 *
 * Every product has at least one variant (a simple product has one default
 * variant). Stock, sales and purchasing always reference product_variant_id.
 * Stock is kept in the variant's BASE unit; product_units defines sell/buy
 * units (e.g. "pack of 10", "box", "meter") with a conversion factor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('parent_id', 'product_categories', nullable: true);
            $table->string('name', 100);
            $table->string('color', 9)->nullable()->comment('POS tile colour');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('name', 100);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('name', 40);
            $table->string('abbreviation', 10);
            $table->boolean('allows_decimal')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('category_id', 'product_categories', nullable: true);
            $table->tenantForeign('brand_id', 'brands', nullable: true);
            $table->tenantForeign('base_unit_id', 'units');
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('type', 20)->default('standard')->comment('standard, service');
            $table->string('tax_class', 20)->default('vatable')->comment('vatable, vat_exempt, zero_rated');
            $table->boolean('sc_pwd_eligible')->default(true);
            $table->boolean('track_inventory')->default(true);
            $table->boolean('track_expiry')->default(false)->comment('Batch/expiry tracking, FEFO consumption');
            $table->boolean('allow_negative_stock')->default(false);
            $table->string('image_path', 255)->nullable();
            $table->json('meta')->nullable()->comment('Vertical-specific: generic_name, requires_prescription, ...');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('product_id', 'products', onDelete: 'cascade');
            $table->string('name', 120)->nullable()->comment('e.g. "Red / XL"; null for default variant');
            $table->string('sku', 60);
            $table->json('attributes')->nullable()->comment('{"size":"XL","color":"Red"}');
            $table->unitCost('cost_price')->default(0)->comment('Reference/last cost; COGS uses FIFO layers');
            $table->money('selling_price');
            $table->money('wholesale_price')->nullable();
            $table->quantity('reorder_level')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'sku']);
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('product_variant_id', 'product_variants', onDelete: 'cascade');
            $table->tenantForeign('unit_id', 'units');
            $table->decimal('conversion_factor', 15, 4)->comment('Base units per 1 of this unit');
            $table->money('selling_price')->nullable();
            $table->money('wholesale_price')->nullable();
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->timestamps();
            $table->unique(['product_variant_id', 'unit_id']);
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('product_variant_id', 'product_variants', onDelete: 'cascade');
            $table->tenantForeign('product_unit_id', 'product_units', nullable: true, onDelete: 'cascade');
            $table->string('barcode', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'barcode']);
        });

        Schema::create('branch_product_prices', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches', onDelete: 'cascade');
            $table->tenantForeign('product_variant_id', 'product_variants', onDelete: 'cascade');
            $table->money('selling_price');
            $table->money('wholesale_price')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product_prices');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('product_categories');
    }
};
