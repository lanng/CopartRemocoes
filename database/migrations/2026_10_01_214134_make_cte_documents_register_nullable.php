<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistência desacoplada (decisão F4): CT-e/MDF-e emitidos no sistema
     * existem por si — sobrevivem à purga dos registros, e o CT-e de tanque
     * nem nasce de um registro. Documentos do Lab continuam atrelados.
     */
    public function up(): void
    {
        Schema::table('cte_documents', function (Blueprint $table) {
            $table->foreignId('register_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cte_documents', function (Blueprint $table) {
            $table->foreignId('register_id')->nullable(false)->change();
        });
    }
};
