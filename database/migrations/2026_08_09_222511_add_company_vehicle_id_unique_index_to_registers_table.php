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
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX registers_company_vehicle_id_unique ON registers (company, vehicle_id) WHERE company <> 'millan'");

            return;
        }

        Schema::table('registers', function (Blueprint $table) {
            $table
                ->string('company_vehicle_id_unique_key')
                ->nullable()
                ->storedAs("CASE WHEN company <> 'millan' THEN CONCAT(company, ':', vehicle_id) ELSE NULL END");
            $table->unique('company_vehicle_id_unique_key', 'registers_company_vehicle_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS registers_company_vehicle_id_unique');

            return;
        }

        Schema::table('registers', function (Blueprint $table) {
            $table->dropUnique('registers_company_vehicle_id_unique');
            $table->dropColumn('company_vehicle_id_unique_key');
        });
    }
};
