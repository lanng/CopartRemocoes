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
        Schema::create('mdfe_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mdfe_document_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 6);
            $table->unsignedTinyInteger('sequence');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status');
            $table->string('event_id', 64)->index();
            $table->string('protocol', 30)->nullable();
            $table->date('event_date')->nullable();
            $table->char('c_uf', 2)->nullable();
            $table->char('c_mun', 7)->nullable();
            $table->longText('payload_xml')->nullable();
            $table->longText('response_xml')->nullable();
            $table->string('xml_path')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['mdfe_document_id', 'event_type', 'sequence', 'attempt']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mdfe_events');
    }
};
