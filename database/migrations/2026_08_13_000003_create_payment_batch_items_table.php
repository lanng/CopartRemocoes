<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_id')->constrained()->restrictOnDelete();
            $table->string('vehicle_plate');
            $table->decimal('amount', 12, 2);
            $table->string('cte_number')->nullable();
            $table->timestamp('delivery_confirmed_at');
            $table->timestamps();
            $table->unique('register_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_batch_items');
    }
};
