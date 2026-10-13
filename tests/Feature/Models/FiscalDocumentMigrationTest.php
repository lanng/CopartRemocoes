<?php

namespace Tests\Feature\Models;

use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class FiscalDocumentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiscal_document_index_names_fit_mysql_identifier_limit(): void
    {
        $overlongIndexes = [];

        foreach ([
            'cte_document_mdfe_document',
            'cte_document_nfes',
            'dfe_document_reservations',
            'cte_events',
        ] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (strlen($index['name']) > 64) {
                    $overlongIndexes[] = "{$table}.{$index['name']} (".strlen($index['name']).')';
                }
            }
        }

        $this->assertSame([], $overlongIndexes, 'Indexes exceed MySQL\'s 64-character limit: '.implode(', ', $overlongIndexes));
    }

    public function test_it_resumes_the_fiscal_document_migration_after_partial_ddl(): void
    {
        $cte = CteDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
        ]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
        ]);

        foreach (['cte_events', 'dfe_document_reservations', 'cte_document_nfes'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach (Schema::getIndexes('cte_document_mdfe_document') as $index) {
            if (! in_array($index['columns'], [
                ['cte_document_id', 'mdfe_document_id'],
                ['mdfe_document_id', 'position'],
            ], true)) {
                continue;
            }

            Schema::table('cte_document_mdfe_document', function (Blueprint $table) use ($index): void {
                if ($index['unique']) {
                    $table->dropUnique($index['name']);
                } else {
                    $table->dropIndex($index['name']);
                }
            });
        }

        $migration = require database_path('migrations/2026_10_07_025353_add_independent_fiscal_document_links.php');
        $migration->up();

        $operatorComplementsMigration = require database_path('migrations/2026_10_07_174830_add_operator_complements_user_id_to_cte_document_nfes_table.php');
        $operatorComplementsMigration->up();

        DB::table('cte_document_mdfe_document')->insert([
            'cte_document_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertDatabaseHas('cte_document_mdfe_document', [
            'cte_document_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertTrue(Schema::hasTable('cte_document_nfes'));
        $this->assertTrue(Schema::hasTable('dfe_document_reservations'));
        $this->assertTrue(Schema::hasTable('cte_events'));
        $this->assertTrue(Schema::hasColumn('cte_document_nfes', 'operator_complements_user_id'));
    }

    public function test_it_blocks_rollback_when_independent_documents_have_no_batch(): void
    {
        $cte = CteDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
        ]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
        ]);

        $migration = require database_path('migrations/2026_10_07_025353_add_independent_fiscal_document_links.php');

        try {
            $migration->down();
            $this->fail('The migration rollback should reject independent documents without batch links.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('independent fiscal documents', $exception->getMessage());
        }

        $this->assertModelExists($cte->refresh());
        $this->assertModelExists($mdfe->refresh());
        $this->assertTrue(Schema::hasTable('cte_events'));
        $this->assertTrue(Schema::hasColumn('cte_documents', 'fiscal_status'));
    }

    public function test_workflow_origin_columns_allow_existing_documents_to_remain_unclassified(): void
    {
        $this->assertTrue(Schema::hasColumn('cte_documents', 'workflow_origin'));
        $this->assertTrue(Schema::hasColumn('mdfe_documents', 'workflow_origin'));

        $cte = CteDocument::factory()->create(['emitter' => 'direct']);
        $mdfe = MdfeDocument::factory()->create(['emitter' => 'direct']);

        $this->assertNull($cte->getRawOriginal('workflow_origin'));
        $this->assertNull($mdfe->getRawOriginal('workflow_origin'));
    }

    public function test_legacy_emitter_migration_preserves_fiscal_history_and_removes_only_agent_schema(): void
    {
        $migration = require database_path('migrations/2026_10_12_005447_remove_legacy_emitter_agent_schema.php');
        $migration->down();

        $agentId = DB::table('cte_agents')->insertGetId([
            'singleton_key' => 'host',
            'name' => 'Emissor legado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batch = CteEmissionBatch::factory()->create();
        $cte = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'emitter' => 'lab',
            'status' => 'authorized',
            'workflow_origin' => null,
            'cte_number' => '123456',
            'access_key' => '35261012563112000130570010000012341000000000',
            'protocol' => '135260000123456',
            'payload_xml' => '<CTe/>',
            'response_xml' => '<retCTe/>',
            'authorization_started_at' => now()->subMinute(),
            'result_payload_hash' => hash('sha256', 'cte-result'),
        ]);
        $currentCte = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'emitter' => 'direct',
            'workflow_origin' => 'controlled_v1',
        ]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'emitter' => 'agent',
            'status' => 'authorized',
            'mdfe_number' => '000000123',
            'access_key' => '35261012563112000130580010000001231000000000',
            'protocol' => '135260000123457',
            'snapshot' => ['historical' => 'kept'],
            'authorization_started_at' => now()->subMinute(),
            'result_payload_hash' => hash('sha256', 'mdfe-result'),
        ]);
        $currentMdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'emitter' => 'direct',
        ]);

        foreach ([$cte, $mdfe] as $document) {
            DB::table($document->getTable())->where('id', $document->id)->update([
                'claimed_by' => $agentId,
                'claim_token_hash' => hash('sha256', 'old-claim'),
                'claimed_at' => now(),
                'claim_expires_at' => now()->addMinute(),
            ]);
        }

        DB::table('cte_document_mdfe_document')->insert([
            'cte_document_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyTokenId = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => 'App\\Models\\CteAgent',
            'tokenable_id' => $agentId,
            'name' => 'legacy',
            'token' => hash('sha256', 'legacy-token'),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userTokenId = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => 999,
            'name' => 'preserved',
            'token' => hash('sha256', 'preserved-token'),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame('lab', $cte->fresh()->emitter);
        $this->assertSame('123456', $cte->fresh()->cte_number);
        $this->assertSame('35261012563112000130570010000012341000000000', $cte->fresh()->access_key);
        $this->assertSame('<retCTe/>', $cte->fresh()->response_xml);
        $this->assertSame(hash('sha256', 'cte-result'), $cte->fresh()->result_payload_hash);
        $this->assertNotNull($cte->fresh()->authorization_started_at);
        $this->assertSame('direct', $currentCte->fresh()->emitter);
        $this->assertSame('agent', $mdfe->fresh()->emitter);
        $this->assertSame('000000123', $mdfe->fresh()->mdfe_number);
        $this->assertSame(['historical' => 'kept'], $mdfe->fresh()->snapshot);
        $this->assertSame(hash('sha256', 'mdfe-result'), $mdfe->fresh()->result_payload_hash);
        $this->assertNotNull($mdfe->fresh()->authorization_started_at);
        $this->assertSame('direct', $currentMdfe->fresh()->emitter);
        $this->assertSame(1, DB::table('cte_document_mdfe_document')->where('cte_document_id', $cte->id)->count());
        $this->assertFalse(Schema::hasTable('cte_agents'));

        foreach (['cte_documents', 'mdfe_documents'] as $tableName) {
            foreach (['claimed_by', 'claim_token_hash', 'claimed_at', 'claim_expires_at'] as $column) {
                $this->assertFalse(Schema::hasColumn($tableName, $column));
            }

            $emitter = collect(Schema::getColumns($tableName))->firstWhere('name', 'emitter');
            $this->assertSame('direct', trim((string) $emitter['default'], "'\""));
        }

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $legacyTokenId]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $userTokenId]);
    }
}
