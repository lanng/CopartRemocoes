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
        Schema::create('integration_inbox_items', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_id');
            $table->string('status');
            $table->string('sender')->nullable();
            $table->text('subject')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('extracted_vehicle_id')->nullable();
            $table->string('extracted_vehicle_plate')->nullable();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unique(['source', 'external_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_inbox_items');
    }
};
