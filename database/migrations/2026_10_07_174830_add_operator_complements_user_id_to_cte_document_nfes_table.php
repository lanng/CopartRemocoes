<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cte_document_nfes', function (Blueprint $table): void {
            $table->foreignId('operator_complements_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cte_document_nfes', function (Blueprint $table): void {
            $table->dropForeign(['operator_complements_user_id']);
            $table->dropColumn('operator_complements_user_id');
        });
    }
};
