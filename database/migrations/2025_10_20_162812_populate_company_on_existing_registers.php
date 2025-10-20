<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('registers')
            ->whereNull('company')
            ->update(['company' => 'copart']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
