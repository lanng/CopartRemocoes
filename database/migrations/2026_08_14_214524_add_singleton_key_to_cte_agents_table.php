<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cte_agents', function (Blueprint $table) {
            $table->string('singleton_key')->default('host')->after('id');
        });

        $agentIds = DB::table('cte_agents')->orderBy('id')->pluck('id');

        foreach ($agentIds->skip(1) as $agentId) {
            DB::table('cte_agents')
                ->where('id', $agentId)
                ->update(['singleton_key' => "legacy-{$agentId}"]);
        }

        Schema::table('cte_agents', function (Blueprint $table) {
            $table->unique('singleton_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cte_agents', function (Blueprint $table) {
            $table->dropUnique(['singleton_key']);
            $table->dropColumn('singleton_key');
        });
    }
};
