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
            $table->string('previous_register_status')->nullable()->after('register_id');
            $table->string('delivery_alert')->nullable()->index()->after('previous_register_status');
            $table->string('authorized_cte_number_at_delivery')->nullable()->after('delivery_alert');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integration_inbox_items', function (Blueprint $table) {
            $table->dropIndex(['delivery_alert']);
            $table->dropColumn([
                'previous_register_status',
                'delivery_alert',
                'authorized_cte_number_at_delivery',
            ]);
        });
    }
};
