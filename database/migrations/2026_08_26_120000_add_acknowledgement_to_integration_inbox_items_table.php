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
        Schema::table('integration_inbox_items', function (Blueprint $table) {
            $table->foreignId('acknowledged_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledged_by')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integration_inbox_items', function (Blueprint $table) {
            $table->dropForeign(['acknowledged_by']);
            $table->dropIndex(['acknowledged_at']);
            $table->dropColumn(['acknowledged_by', 'acknowledged_at']);
        });
    }
};
