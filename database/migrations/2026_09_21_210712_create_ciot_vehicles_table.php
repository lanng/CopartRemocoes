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
        Schema::create('ciot_vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate', 7)->unique();
            $table->string('rntrc', 9)->nullable();
            $table->unsignedTinyInteger('axles');
            $table->string('type', 20)->default('automotor');
            $table->string('description', 150)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ciot_vehicles');
    }
};
