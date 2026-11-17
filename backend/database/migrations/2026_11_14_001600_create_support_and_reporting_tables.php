<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('user_id', 'users');
            $table->string('number', 30)->unique();
            $table->string('subject', 200);
            $table->string('category', 30)->nullable();
            $table->string('priority', 10)->default('normal')->comment('low, normal, high, urgent');
            $table->string('status', 20)->default('open')->comment('open, pending, resolved, closed');
            $table->foreignId('assigned_to')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'priority']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('support_ticket_id', 'support_tickets', onDelete: 'cascade');
            $table->string('author_type', 20)->comment('user, platform_admin');
            $table->unsignedBigInteger('author_id');
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_sales_summaries', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches');
            $table->date('business_date');
            $table->unsignedInteger('transaction_count')->default(0);
            $table->money('gross_sales')->default(0);
            $table->money('discounts')->default(0);
            $table->money('refunds')->default(0);
            $table->money('net_sales')->default(0);
            $table->money('vat_amount')->default(0);
            $table->money('cogs')->default(0);
            $table->json('payment_breakdown')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'business_date']);
            $table->index(['tenant_id', 'business_date']);
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('user_id', 'users');
            $table->string('report', 60);
            $table->string('format', 10)->comment('csv, xlsx, pdf');
            $table->json('filters')->nullable();
            $table->string('status', 20)->default('queued')->comment('queued, processing, ready, failed');
            $table->string('path', 255)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('daily_sales_summaries');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
