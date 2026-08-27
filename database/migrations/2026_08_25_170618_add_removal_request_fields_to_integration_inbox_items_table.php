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
        Schema::table('integration_inbox_items', function (Blueprint $table): void {
            $table->string('message_type')->default('checklist')->index()->after('source');
            $table->json('extracted_data')->nullable()->after('extracted_vehicle_plate');
            $table->json('proposed_changes')->nullable()->after('extracted_data');
            $table->json('alerts')->nullable()->after('proposed_changes');
            $table->string('candidate_pdf_path')->nullable()->after('alerts');
            $table->char('candidate_pdf_sha256', 64)->nullable()->after('candidate_pdf_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integration_inbox_items', function (Blueprint $table): void {
            $table->dropIndex(['message_type']);
            $table->dropColumn([
                'message_type',
                'extracted_data',
                'proposed_changes',
                'alerts',
                'candidate_pdf_path',
                'candidate_pdf_sha256',
            ]);
        });
    }
};
