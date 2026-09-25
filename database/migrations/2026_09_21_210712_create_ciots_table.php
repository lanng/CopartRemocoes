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
        Schema::create('ciots', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('status');
            $table->string('line');
            $table->string('operation_type');
            $table->string('id_operacao_transporte', 12)->unique()->nullable();
            $table->foreignId('payer_id')->nullable()->constrained('ciot_payers')->nullOnDelete();
            $table->string('payer_cnpj', 14);
            $table->string('payer_name');
            $table->foreignId('delivery_payer_id')->nullable()->constrained('ciot_payers')->nullOnDelete();
            $table->string('delivery_payer_cnpj', 14)->nullable();
            $table->string('delivery_payer_name', 150)->nullable();
            $table->json('additional_payers')->nullable();
            $table->json('origin');
            $table->json('destination');
            $table->decimal('distance_km', 8, 2);
            $table->unsignedBigInteger('freight_value_cents');
            $table->decimal('cargo_weight_kg', 10, 2)->nullable();
            $table->json('vehicles');
            $table->json('indicators')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->string('ciot_number', 12)->nullable()->unique();
            $table->string('verifier_code', 4)->nullable();
            $table->string('protocol', 16)->nullable();
            $table->text('carrier_notice')->nullable();
            $table->timestamp('travel_start_at')->nullable();
            $table->timestamp('travel_end_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->string('error_code', 20)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('cte_emission_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ciots');
    }
};
