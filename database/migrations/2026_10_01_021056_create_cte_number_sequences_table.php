<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sequência própria de numeração do emissor direto (spec §3): o primeiro
     * nCT de produção é semeado manualmente no switch (último nCT do Lab + 1).
     */
    public function up(): void
    {
        Schema::create('cte_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj', 14);
            $table->unsignedSmallInteger('serie');
            $table->unsignedBigInteger('next_number');
            $table->timestamps();

            $table->unique(['cnpj', 'serie']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cte_number_sequences');
    }
};
