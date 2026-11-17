<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level (non-tenant) tables: plans, tenants, platform admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->money('monthly_price');
            $table->money('annual_price');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->unsignedInteger('max_branches')->nullable()->comment('null = unlimited');
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_terminals')->nullable();
            $table->unsignedInteger('max_products')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 60);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['subscription_plan_id', 'feature_key']);
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->comment('Trade / display name');
            $table->string('slug', 60)->unique();
            $table->string('subdomain', 30)->nullable()->unique();
            $table->string('status', 20)->default('trial')->index()
                ->comment('trial, active, past_due, suspended, cancelled');
            $table->string('business_type', 40)->nullable();

            // BIR / registration details
            $table->string('registered_name', 200)->nullable();
            $table->string('business_style', 200)->nullable();
            $table->string('tin', 20)->nullable();
            $table->string('tin_branch_code', 5)->default('00000');
            $table->string('rdo_code', 5)->nullable();
            $table->boolean('vat_registered')->default(false);

            // Contact & address (PSGC codes)
            $table->string('email', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('region_code', 10)->nullable();
            $table->string('province_code', 10)->nullable();
            $table->string('city_code', 10)->nullable();
            $table->string('barangay_code', 10)->nullable();
            $table->string('postal_code', 10)->nullable();

            $table->string('logo_path', 255)->nullable();
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->char('currency', 3)->default('PHP');
            $table->string('locale', 10)->default('en_PH');

            $table->unsignedBigInteger('owner_user_id')->nullable(); // FK added after users table exists
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 150)->unique();
            $table->string('password');
            $table->string('role', 20)->default('super_admin')->comment('super_admin, support, billing');
            $table->boolean('is_active')->default(true);
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_admins');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('subscription_plans');
    }
};
