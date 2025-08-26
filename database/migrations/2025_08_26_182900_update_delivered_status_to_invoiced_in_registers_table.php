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
        DB::table('registers')->where('status', 'delivered')->update(['status' => 'invoiced']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('registers')->where('status', 'invoiced')->update(['status' => 'delivered']);
    }
};
