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
        Schema::table('registers', function (Blueprint $table) {
            $table->string('consignor_letter_path')->nullable()->after('pdf_sha256');
            $table->char('consignor_letter_sha256', 64)->nullable()->after('consignor_letter_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registers', function (Blueprint $table) {
            $table->dropColumn([
                'consignor_letter_path',
                'consignor_letter_sha256',
            ]);
        });
    }
};
