<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting services: tenant settings, document numbering, idempotency,
 * audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('scope_type', 20)->comment('tenant, branch, terminal');
            $table->unsignedBigInteger('scope_id')->default(0)->comment('0 when scope_type = tenant');
            $table->string('doc_type', 40)->comment('sales_invoice, refund, purchase_order, ...');
            $table->string('prefix', 20)->default('');
            $table->unsignedTinyInteger('padding')->default(9);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedInteger('reset_counter')->default(0)->comment('BIR: number of resets (should stay 0)');
            $table->timestamps();
            $table->unique(['tenant_id', 'scope_type', 'scope_id', 'doc_type'], 'document_sequences_scope_unique');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('key', 100);
            $table->string('route', 150);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'key', 'route']);
        });

        // Append-only. Never updated or deleted by the application.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete()
                ->comment('null = platform-level event');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('actor_type', 40)->nullable()->comment('user, platform_admin, terminal, system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 150)->nullable();
            $table->unsignedBigInteger('authorized_by_id')->nullable()->comment('Supervisor who approved an override');
            $table->string('event', 80);
            $table->string('auditable_type', 80)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'event']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('tenant_settings');
    }
};
