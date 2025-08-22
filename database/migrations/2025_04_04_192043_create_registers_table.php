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
            $table->string('vehicle_model', 30);
            $table->string('vehicle_plate', 7);
            $table->string('origin_city', 50);
            $table->string('destination_city', 20);
            $table->date('deadline_withdraw');
            $table->date('deadline_delivery');
            $table->date('collected_date')->nullable();
            $table->string('driver', 30)->nullable();
            $table->string('driver_plate', 7)->nullable();
            $table->string('vehicle_id', 10);
            $table->decimal('value', 6, 2);
            $table->string('status')->default(RegisterStatusEnum::PENDING);
            $table->string('pdf_path')->nullable();
            $table->longText('notes')->nullable()->change();
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
