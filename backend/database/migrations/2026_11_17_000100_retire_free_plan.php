<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AvantPOS no longer offers a Free plan: new businesses get a trial instead.
 * The plan row is kept (history and invoices may refer to it) but hidden and
 * no longer offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plans')->where('code', 'free')
            ->update(['is_active' => false, 'is_public' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('subscription_plans')->where('code', 'free')
            ->update(['is_active' => true, 'is_public' => true, 'updated_at' => now()]);
    }
};
