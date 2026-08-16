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
        Schema::create('cte_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('cte_emission_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_id')->constrained()->restrictOnDelete();
            $table->foreignId('replaced_document_id')->nullable()->constrained('cte_documents')->nullOnDelete();
            $table->string('status');
            $table->json('snapshot');
            $table->uuid('idempotency_key')->unique();
            $table->string('execution_mode');
            $table->foreignId('claimed_by')->nullable()->constrained('cte_agents')->nullOnDelete();
            $table->string('claim_token_hash', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('authorization_started_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->string('cte_number', 20)->nullable();
            $table->char('access_key', 44)->nullable()->unique();
            $table->string('series', 10)->nullable();
            $table->string('protocol', 30)->nullable();
            $table->string('fiscal_status_code', 10)->nullable();
            $table->text('fiscal_status_message')->nullable();
            $table->string('error_stage', 50)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->char('result_payload_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cte_documents');
    }
};
