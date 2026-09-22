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
        Schema::create('city_distances', function (Blueprint $table) {
            $table->id();
            $table->string('origin_ibge', 7);
            $table->string('destination_ibge', 7);
            $table->decimal('km', 8, 1);
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['origin_ibge', 'destination_ibge']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('city_distances');
    }
};
