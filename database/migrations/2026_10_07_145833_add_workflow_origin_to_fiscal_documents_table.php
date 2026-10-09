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
        Schema::table('cte_documents', function (Blueprint $table): void {
            $table->string('workflow_origin')->nullable()->after('emitter');
        });

        Schema::table('mdfe_documents', function (Blueprint $table): void {
            $table->string('workflow_origin')->nullable()->after('emitter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mdfe_documents', function (Blueprint $table): void {
            $table->dropColumn('workflow_origin');
        });

        Schema::table('cte_documents', function (Blueprint $table): void {
            $table->dropColumn('workflow_origin');
        });
    }
};
