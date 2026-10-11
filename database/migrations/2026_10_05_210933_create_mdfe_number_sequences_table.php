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
        Schema::create('mdfe_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->char('cnpj', 14);
            $table->unsignedSmallInteger('series');
            $table->unsignedBigInteger('next_number');
            $table->timestamps();

            $table->unique(['cnpj', 'series']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mdfe_number_sequences');
    }
};
