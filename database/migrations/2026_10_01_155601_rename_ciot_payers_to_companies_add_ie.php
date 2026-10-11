<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O cadastro deixa de ser exclusivo do CIOT (ciot_payers) e passa a ser o
     * registro fiscal neutro de empresas — pátios, seguradoras e distribuidoras
     * — consumido por CIOT e CT-e. A IE entra para a emissão de CT-e e é
     * obrigatória em novas criações (validação no formulário), mas permanece
     * nullable para não forçar backfill retroativo dos pagantes existentes.
     */
    public function up(): void
    {
        Schema::table('ciots', function (Blueprint $table) {
            $table->dropForeign(['payer_id']);
            $table->dropForeign(['delivery_payer_id']);
        });

        Schema::rename('ciot_payers', 'companies');

        Schema::table('ciots', function (Blueprint $table) {
            $table->foreign('payer_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('delivery_payer_id')->references('id')->on('companies')->nullOnDelete();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('ie')->nullable()->after('cnpj');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('ie');
        });

        Schema::table('ciots', function (Blueprint $table) {
            $table->dropForeign(['payer_id']);
            $table->dropForeign(['delivery_payer_id']);
        });

        Schema::rename('companies', 'ciot_payers');

        Schema::table('ciots', function (Blueprint $table) {
            $table->foreign('payer_id')->references('id')->on('ciot_payers')->nullOnDelete();
            $table->foreign('delivery_payer_id')->references('id')->on('ciot_payers')->nullOnDelete();
        });
    }
};
