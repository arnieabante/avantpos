<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Philippine Standard Geographic Code (PSGC) reference data. Global, read-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ph_regions', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 150);
            $table->string('region_name', 150)->nullable()->comment('e.g. Region IV-A');
        });

        Schema::create('ph_provinces', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 150);
            $table->string('region_code', 10)->index();
            $table->foreign('region_code')->references('code')->on('ph_regions');
        });

        Schema::create('ph_cities', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 150);
            $table->string('type', 20)->comment('city, municipality, sub_municipality');
            $table->string('region_code', 10)->index();
            $table->string('province_code', 10)->nullable()->index()->comment('null for NCR cities / districts');
            $table->foreign('region_code')->references('code')->on('ph_regions');
        });

        Schema::create('ph_barangays', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 150);
            $table->string('city_code', 10)->index();
            $table->foreign('city_code')->references('code')->on('ph_cities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ph_barangays');
        Schema::dropIfExists('ph_cities');
        Schema::dropIfExists('ph_provinces');
        Schema::dropIfExists('ph_regions');
    }
};
