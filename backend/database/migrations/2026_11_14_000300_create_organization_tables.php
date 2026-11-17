<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches, POS terminals (devices) and cash registers (drawers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('region_code', 10)->nullable();
            $table->string('province_code', 10)->nullable();
            $table->string('city_code', 10)->nullable();
            $table->string('barangay_code', 10)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('bir_branch_code', 5)->nullable()->comment('TIN branch code, e.g. 00001');
            $table->json('operating_hours')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('terminals', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->string('code', 20);
            $table->string('name', 80);
            $table->string('status', 20)->default('active')->comment('active, inactive, retired');

            // BIR machine registration
            $table->string('min', 30)->nullable()->comment('Machine Identification Number');
            $table->string('serial_no', 60)->nullable();
            $table->string('ptu_no', 60)->nullable()->comment('Permit To Use');
            $table->date('ptu_issued_at')->nullable();
            $table->date('ptu_valid_until')->nullable();

            // Device / offline sync
            $table->string('device_label', 120)->nullable();
            $table->string('device_platform', 30)->nullable()->comment('windows, android, ios, sunmi...');
            $table->timestamp('device_registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('terminal_id', 'terminals', nullable: true);
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
        Schema::dropIfExists('terminals');
        Schema::dropIfExists('branches');
    }
};
