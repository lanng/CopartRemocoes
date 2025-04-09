<?php

use App\Enums\RegisterStatusEnum;
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
        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('vehicle_model');
            $table->string('vehicle_plate');
            $table->string('origin_city');
            $table->string('destination_city');
            $table->date('deadline_withdraw');
            $table->date('deadline_delivery');
            $table->date('collected_date')->nullable();
            $table->string('driver')->nullable();
            $table->string('driver_plate')->nullable();
            $table->string('vehicle_id');
            $table->decimal('value');
            $table->string('status')->default(RegisterStatusEnum::PENDING);
            $table->string('pdf_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registers');
    }
};
