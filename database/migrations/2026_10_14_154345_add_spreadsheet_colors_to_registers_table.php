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
        Schema::table('registers', function (Blueprint $table) {
            $table->string('vehicle_plate_color', 16)->nullable();
            $table->string('vehicle_model_color', 16)->nullable();
            $table->string('origin_city_color', 16)->nullable();
            $table->string('destination_city_color', 16)->nullable();
            $table->string('deadline_withdraw_color', 16)->nullable();
            $table->string('deadline_delivery_color', 16)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registers', function (Blueprint $table) {
            $table->dropColumn([
                'vehicle_plate_color',
                'vehicle_model_color',
                'origin_city_color',
                'destination_city_color',
                'deadline_withdraw_color',
                'deadline_delivery_color',
            ]);
        });
    }
};
