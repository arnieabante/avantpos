<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Register sessions (shifts), cash movements and X/Z readings.
 *
 * Sessions may be opened OFFLINE on a terminal, so they carry a client UUID
 * for idempotent sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->uuid('client_uuid');
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('terminal_id', 'terminals');
            $table->tenantForeign('cash_register_id', 'cash_registers', nullable: true);
            $table->tenantForeign('user_id', 'users');
            $table->string('status', 20)->default('open')->comment('open, closed');
            $table->timestamp('opened_at');
            $table->money('opening_float');
            $table->timestamp('closed_at')->nullable();
            $table->tenantForeign('closed_by', 'users', nullable: true);
            $table->money('expected_cash')->nullable();
            $table->money('counted_cash')->nullable();
            $table->money('variance')->nullable();
            $table->json('denominations')->nullable();
            $table->text('closing_notes')->nullable();
            $table->string('origin', 10)->default('online')->comment('online, offline');
            $table->timestamps();

            $table->unique(['tenant_id', 'client_uuid']);
            // At most one OPEN session per terminal (NULL when closed => not unique-checked).
            $table->unsignedBigInteger('open_terminal_id')
                ->storedAs("IF(`status` = 'open', `terminal_id`, NULL)")->nullable();
            $table->unique(['tenant_id', 'open_terminal_id'], 'cash_register_sessions_one_open_per_terminal');
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->uuid('client_uuid');
            $table->tenantForeign('cash_register_session_id', 'cash_register_sessions');
            $table->string('type', 20)->comment('cash_in, cash_out, payout');
            $table->money('amount');
            $table->string('reason', 255);
            $table->tenantForeign('user_id', 'users');
            $table->tenantForeign('authorized_by', 'users', nullable: true);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'client_uuid']);
        });

        Schema::create('register_readings', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('terminal_id', 'terminals');
            $table->tenantForeign('cash_register_session_id', 'cash_register_sessions', nullable: true);
            $table->string('type', 1)->comment('X or Z');
            $table->unsignedBigInteger('reading_no');
            $table->date('business_date');
            $table->string('beginning_invoice_no', 30)->nullable();
            $table->string('ending_invoice_no', 30)->nullable();
            $table->unsignedInteger('transaction_count')->default(0);
            $table->money('gross_sales')->default(0);
            $table->money('vatable_sales')->default(0);
            $table->money('vat_amount')->default(0);
            $table->money('vat_exempt_sales')->default(0);
            $table->money('zero_rated_sales')->default(0);
            $table->money('sc_discount')->default(0);
            $table->money('pwd_discount')->default(0);
            $table->money('other_discount')->default(0);
            $table->money('void_amount')->default(0);
            $table->money('refund_amount')->default(0);
            $table->money('net_sales')->default(0);
            $table->money('accumulated_grand_total_before')->default(0);
            $table->money('accumulated_grand_total_after')->default(0)->comment('Non-resettable');
            $table->json('payment_breakdown')->nullable();
            $table->tenantForeign('generated_by', 'users');
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->unique(['terminal_id', 'type', 'reading_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('register_readings');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_register_sessions');
    }
};
