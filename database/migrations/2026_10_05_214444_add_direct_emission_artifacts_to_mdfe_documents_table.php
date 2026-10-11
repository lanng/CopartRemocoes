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
        Schema::table('mdfe_documents', function (Blueprint $table): void {
            $table->string('emitter')->default('agent')->after('execution_mode');
            $table->longText('payload_xml')->nullable();
            $table->longText('response_xml')->nullable();
            $table->string('digest_value', 100)->nullable();
            $table->string('xml_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->char('sha256', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mdfe_documents', function (Blueprint $table): void {
            $table->dropColumn([
                'emitter', 'payload_xml', 'response_xml', 'digest_value', 'xml_path', 'pdf_path', 'sha256',
            ]);
        });
    }
};
