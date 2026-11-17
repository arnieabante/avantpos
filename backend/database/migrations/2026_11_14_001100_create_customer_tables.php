<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('name', 80);
            $table->string('price_tier', 15)->default('retail')->comment('retail, wholesale');
            $table->rate('discount_percent')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('customer_group_id', 'customer_groups', nullable: true);
            $table->string('code', 30)->nullable();
            $table->string('name', 150);
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('tin', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('sc_pwd_id_type', 10)->nullable()->comment('senior, pwd');
            $table->text('sc_pwd_id_no')->nullable()->comment('Encrypted at rest (Data Privacy Act)');
            $table->decimal('loyalty_points', 15, 2)->default(0);
            $table->boolean('allow_credit')->default(false);
            $table->money('credit_limit')->default(0);
            $table->money('balance')->default(0)->comment('Cached outstanding balance; ledger is source of truth');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('customer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('customer_id', 'customers');
            $table->string('type', 20)->comment('credit_sale, payment, refund, adjustment');
            $table->money('amount')->comment('Signed: + increases balance owed');
            $table->money('balance_after');
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->tenantForeign('user_id', 'users', nullable: true);
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'customer_id', 'created_at']);
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('customer_id', 'customers');
            $table->decimal('points', 15, 2)->comment('Signed');
            $table->string('reason', 30)->comment('earn, redeem, adjust, expire, refund');
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->tenantForeign('user_id', 'users', nullable: true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('customer_ledger_entries');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_groups');
    }
};
