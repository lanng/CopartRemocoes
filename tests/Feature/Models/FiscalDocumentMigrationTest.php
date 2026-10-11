<?php

namespace Tests\Feature\Models;

use App\Models\CteDocument;
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
}
