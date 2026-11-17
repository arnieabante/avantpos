<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5B: loyalty points on sales. Redeemed points are a sales discount
 * (they reduce the VAT base); earned points are recorded for reversal on void/refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->money('loyalty_discount')->default(0)->after('sc_pwd_discount_total');
            $table->decimal('points_redeemed', 15, 2)->default(0)->after('loyalty_discount');
            $table->decimal('points_earned', 15, 2)->default(0)->after('points_redeemed');
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['loyalty_discount', 'points_redeemed', 'points_earned']));
    }
};
