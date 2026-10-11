<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cte_documents', 'fiscal_status')) {
            Schema::table('cte_documents', function (Blueprint $table): void {
                $table->dropForeign(['cte_emission_batch_id']);
                $table->foreignId('cte_emission_batch_id')->nullable()->change();
                $table->foreign('cte_emission_batch_id')->references('id')->on('cte_emission_batches')->nullOnDelete();
                $table->string('fiscal_status')->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('mdfe_documents', 'fiscal_status')) {
            Schema::table('mdfe_documents', function (Blueprint $table): void {
                $table->dropForeign(['cte_emission_batch_id']);
                $table->foreignId('cte_emission_batch_id')->nullable()->change();
                $table->foreign('cte_emission_batch_id')->references('id')->on('cte_emission_batches')->nullOnDelete();
                $table->foreignId('ciot_id')->nullable()->after('cte_emission_batch_id')->constrained('ciots')->restrictOnDelete();
                $table->string('fiscal_status')->nullable()->after('status');
            });
        }

        if (! Schema::hasTable('cte_document_mdfe_document')) {
            Schema::create('cte_document_mdfe_document', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cte_document_id')->constrained()->restrictOnDelete();
                $table->foreignId('mdfe_document_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->unique(['cte_document_id', 'mdfe_document_id'], 'cte_doc_mdfe_pair_unique');
                $table->index(['mdfe_document_id', 'position'], 'cte_mdfe_position_idx');
            });
        }

        $cteMdfeIndexes = Schema::getIndexListing('cte_document_mdfe_document');

        if (! in_array('cte_doc_mdfe_pair_unique', $cteMdfeIndexes, true)) {
            Schema::table('cte_document_mdfe_document', function (Blueprint $table): void {
                $table->unique(['cte_document_id', 'mdfe_document_id'], 'cte_doc_mdfe_pair_unique');
            });
        }

        if (! in_array('cte_mdfe_position_idx', $cteMdfeIndexes, true)) {
            Schema::table('cte_document_mdfe_document', function (Blueprint $table): void {
                $table->index(['mdfe_document_id', 'position'], 'cte_mdfe_position_idx');
            });
        }

        if (! Schema::hasTable('cte_document_nfes')) {
            Schema::create('cte_document_nfes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cte_document_id')->constrained()->restrictOnDelete();
                $table->char('access_key', 44);
                $table->string('number', 20)->nullable();
                $table->string('series', 10)->nullable();
                $table->string('source_filename')->nullable();
                $table->string('xml_path');
                $table->char('sha256', 64);
                $table->json('extracted_data');
                $table->json('operator_complements')->nullable();
                $table->timestamps();
                $table->unique(['cte_document_id', 'access_key']);
                $table->index('access_key');
            });
        }

        if (! Schema::hasTable('dfe_document_reservations')) {
            Schema::create('dfe_document_reservations', function (Blueprint $table): void {
                $table->id();
                $table->string('resource_type', 20);
                $table->unsignedBigInteger('resource_id');
                $table->foreignId('mdfe_document_id')->constrained()->restrictOnDelete();
                $table->timestamps();
                $table->unique(['resource_type', 'resource_id']);
                $table->index('mdfe_document_id');
            });
        }

        if (! Schema::hasTable('cte_events')) {
            Schema::create('cte_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cte_document_id')->constrained()->restrictOnDelete();
                $table->string('event_type', 6);
                $table->unsignedTinyInteger('sequence');
                $table->unsignedSmallInteger('attempt')->default(1);
                $table->string('status');
                $table->string('event_id', 64)->index();
                $table->string('protocol', 30)->nullable();
                $table->longText('payload_xml')->nullable();
                $table->longText('response_xml')->nullable();
                $table->string('xml_path')->nullable();
                $table->char('sha256', 64)->nullable();
                $table->string('error_code', 100)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->unique(['cte_document_id', 'event_type', 'sequence', 'attempt']);
            });
        }

        DB::table('cte_documents')
            ->where('status', 'authorized')
            ->update(['fiscal_status' => 'authorized']);
        DB::table('cte_documents')
            ->where('status', 'cancelled')
            ->update(['fiscal_status' => 'cancelled']);
        DB::table('mdfe_documents')
            ->where('fiscal_status_code', '132')
            ->update(['fiscal_status' => 'closed']);
        DB::table('mdfe_documents')
            ->where('status', 'authorized')
            ->whereNull('fiscal_status')
            ->update(['fiscal_status' => 'authorized']);
    }

    public function down(): void
    {
        if (DB::table('cte_documents')->whereNull('cte_emission_batch_id')->exists()
            || DB::table('mdfe_documents')->whereNull('cte_emission_batch_id')->exists()) {
            throw new \LogicException('Cannot roll back while independent fiscal documents exist without batch links.');
        }

        Schema::dropIfExists('cte_events');
        Schema::dropIfExists('dfe_document_reservations');
        Schema::dropIfExists('cte_document_nfes');
        Schema::dropIfExists('cte_document_mdfe_document');

        Schema::table('mdfe_documents', function (Blueprint $table): void {
            $table->dropForeign(['ciot_id']);
            $table->dropColumn(['ciot_id', 'fiscal_status']);
            $table->dropForeign(['cte_emission_batch_id']);
            $table->foreignId('cte_emission_batch_id')->nullable(false)->change();
            $table->foreign('cte_emission_batch_id')->references('id')->on('cte_emission_batches')->cascadeOnDelete();
        });

        Schema::table('cte_documents', function (Blueprint $table): void {
            $table->dropColumn('fiscal_status');
            $table->dropForeign(['cte_emission_batch_id']);
            $table->foreignId('cte_emission_batch_id')->nullable(false)->change();
            $table->foreign('cte_emission_batch_id')->references('id')->on('cte_emission_batches')->cascadeOnDelete();
        });
    }
};
