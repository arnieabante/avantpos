<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('expense_category_id', 'expense_categories');
            $table->tenantForeign('payment_method_id', 'payment_methods', nullable: true);
            $table->tenantForeign('cash_register_session_id', 'cash_register_sessions', nullable: true)
                ->comment('Set when paid out of a cash drawer');
            $table->string('number', 30);
            $table->date('expense_date');
            $table->money('amount');
            $table->string('vendor', 150)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft')->comment('draft, submitted, approved, rejected, paid');
            $table->tenantForeign('submitted_by', 'users');
            $table->tenantForeign('approved_by', 'users', nullable: true);
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'branch_id', 'expense_date']);
        });

        Schema::create('expense_attachments', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('expense_id', 'expenses', onDelete: 'cascade');
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_attachments');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
