<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos do emissor direto (spec §3): rótulo do emissor, espelho
     * request/response do padrão CIOT, digest para o cross-check do digVal
     * e os caminhos/assinatura do arquivo fiscal de guarda no prefixo dfe/
     * (spec §5.1). pdf_path fica reservado para o DACTE (F4).
     */
    public function up(): void
    {
        Schema::table('cte_documents', function (Blueprint $table) {
            $table->string('emitter')->default('lab')->after('execution_mode');
            $table->mediumText('payload_xml')->nullable()->after('fiscal_status_message');
            $table->mediumText('response_xml')->nullable()->after('payload_xml');
            $table->char('digest_value', 28)->nullable()->after('response_xml');
            $table->string('xml_path')->nullable()->after('digest_value');
            $table->string('pdf_path')->nullable()->after('xml_path');
            $table->char('sha256', 64)->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('cte_documents', function (Blueprint $table) {
            $table->dropColumn([
                'emitter', 'payload_xml', 'response_xml', 'digest_value',
                'xml_path', 'pdf_path', 'sha256',
            ]);
        });
    }
};
