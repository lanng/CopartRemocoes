<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fiscal_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 20);
            $table->string('source_name');
            $table->string('normalized_name');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['role', 'normalized_name'], 'company_fiscal_alias_role_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_fiscal_aliases');
    }
};
