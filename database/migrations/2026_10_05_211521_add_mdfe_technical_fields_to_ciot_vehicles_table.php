<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ciot_vehicles', function (Blueprint $table): void {
            $table->string('renavam', 11)->nullable();
            $table->unsignedMediumInteger('tare_kg')->nullable();
            $table->unsignedMediumInteger('capacity_kg')->nullable();
            $table->unsignedSmallInteger('capacity_m3')->nullable();
            $table->char('type_rod', 2)->nullable();
            $table->char('body_type', 2)->nullable();
            $table->char('uf', 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ciot_vehicles', function (Blueprint $table): void {
            $table->dropColumn([
                'renavam',
                'tare_kg',
                'capacity_kg',
                'capacity_m3',
                'type_rod',
                'body_type',
                'uf',
            ]);
        });
    }
};
