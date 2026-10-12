<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        PersonalAccessToken::query()
            ->where('tokenable_type', 'App\\Models\\CteAgent')
            ->delete();

        foreach (['cte_documents', 'mdfe_documents'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['claimed_by']);
                $table->dropColumn([
                    'claimed_by',
                    'claim_token_hash',
                    'claimed_at',
                    'claim_expires_at',
                ]);
            });
        }

        Schema::dropIfExists('cte_agents');

        Schema::table('cte_documents', function (Blueprint $table): void {
            $table->string('emitter')->default('direct')->change();
        });

        Schema::table('mdfe_documents', function (Blueprint $table): void {
            $table->string('emitter')->default('direct')->change();
        });
    }

    public function down(): void
    {
        Schema::create('cte_agents', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->default('host')->unique();
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('version')->nullable();
            $table->json('capabilities')->nullable();
            $table->boolean('is_dry_run')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        foreach (['cte_documents', 'mdfe_documents'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('claimed_by')->nullable()->constrained('cte_agents')->nullOnDelete();
                $table->string('claim_token_hash', 64)->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('claim_expires_at')->nullable();
            });
        }

        Schema::table('cte_documents', function (Blueprint $table): void {
            $table->string('emitter')->default('lab')->change();
        });

        Schema::table('mdfe_documents', function (Blueprint $table): void {
            $table->string('emitter')->default('agent')->change();
        });
    }
};
