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
        Schema::table('cte_events', function (Blueprint $table): void {
            $table->date('event_date')->nullable()->after('protocol');
            $table->unsignedSmallInteger('transport_http_status')->nullable();
            $table->string('response_content_type')->nullable();
            $table->longText('response_body')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cte_events', function (Blueprint $table): void {
            $table->dropColumn(['event_date', 'transport_http_status', 'response_content_type', 'response_body']);
        });
    }
};
