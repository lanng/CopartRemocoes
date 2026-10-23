<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cte_document_nfes', function (Blueprint $table): void {
            $table->string('xml_path')->nullable()->change();
            $table->char('sha256', 64)->nullable()->change();
            $table->json('extracted_data')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('cte_document_nfes')
            ->whereNull('xml_path')
            ->orWhereNull('sha256')
            ->orWhereNull('extracted_data')
            ->exists()) {
            throw new LogicException('Não é possível reverter enquanto houver referências de NF-e sem XML original.');
        }

        Schema::table('cte_document_nfes', function (Blueprint $table): void {
            $table->string('xml_path')->nullable(false)->change();
            $table->char('sha256', 64)->nullable(false)->change();
            $table->json('extracted_data')->nullable(false)->change();
        });
    }
};
