<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts whose password was chosen by someone else (seeded, added by an
 * administrator, reset by another admin) must set their own password at their
 * first sign-in before they can do anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'platform_admins'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->boolean('must_change_password')->default(false)->after('password'));
        }
    }

    public function down(): void
    {
        foreach (['users', 'platform_admins'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('must_change_password'));
        }
    }
};
