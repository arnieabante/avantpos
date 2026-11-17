<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant users, branch access, invitations, sessions and password resets.
 *
 * One e-mail = one tenant (email is globally unique) which keeps login and
 * tenant resolution unambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('name', 120);
            $table->string('email', 150)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone', 20)->nullable();
            $table->string('pin_hash')->nullable()->comment('POS quick switch / supervisor override PIN');
            $table->string('status', 20)->default('active')->comment('active, inactive');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->tenantForeign('branch_id', 'branches', onDelete: 'cascade');
            $table->tenantForeign('user_id', 'users', onDelete: 'cascade');
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
        });

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->tenantKey();
            $table->string('email', 150);
            $table->string('name', 120)->nullable();
            $table->unsignedBigInteger('role_id');
            $table->json('branch_ids')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->tenantForeign('invited_by', 'users', nullable: true);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'email']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('branch_user');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropForeign(['owner_user_id']));
        Schema::dropIfExists('users');
    }
};
