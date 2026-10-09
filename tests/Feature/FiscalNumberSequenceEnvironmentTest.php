<?php

namespace Tests\Feature;

use App\Models\CteNumberSequence;
use App\Models\MdfeNumberSequence;
use App\Services\Cte\AllocateCteNumber;
use App\Services\Dfe\AllocateMdfeNumber;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FiscalNumberSequenceEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public static function allocators(): array
    {
        return [
            'CT-e' => [AllocateCteNumber::class, CteNumberSequence::class, 'serie', 'dfe.numbering_start', 3008],
            'MDF-e' => [AllocateMdfeNumber::class, MdfeNumberSequence::class, 'series', 'dfe.mdfe.numbering_start', 280],
        ];
    }

    #[DataProvider('allocators')]
    public function test_homologation_and_production_keep_independent_counters(string $allocator, string $model, string $series, string $configKey, int $productionStart): void
    {
        config(['dfe.emit.cnpj' => '12563112000130', 'dfe.cte.serie' => 1, 'dfe.mdfe.series' => 1]);
        config(['dfe.tp_amb' => '2', $configKey => 900000016]);
        $this->assertSame(900000016, app($allocator)->handle());
        config(['dfe.tp_amb' => '1', $configKey => $productionStart]);
        $this->assertSame($productionStart, app($allocator)->handle());
        config(['dfe.tp_amb' => '2', $configKey => 900000016]);
        $this->assertSame(900000017, app($allocator)->handle());
        config(['dfe.tp_amb' => '1', $configKey => $productionStart]);
        $this->assertSame($productionStart + 1, app($allocator)->handle());
        $this->assertDatabaseHas($model, ['tp_amb' => 1, $series => 1, 'next_number' => $productionStart + 2]);
        $this->assertDatabaseHas($model, ['tp_amb' => 2, $series => 1, 'next_number' => 900000018]);
        $this->assertSame(2, $model::count());
    }

    #[DataProvider('allocators')]
    public function test_invalid_environment_cannot_allocate_a_number(string $allocator, string $model, string $series, string $configKey, int $productionStart): void
    {
        config(['dfe.tp_amb' => '3', $configKey => $productionStart]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('DFE_TP_AMB');
        app($allocator)->handle();
    }

    #[DataProvider('allocators')]
    public function test_unclassified_legacy_counter_is_not_ignored(string $allocator, string $model, string $series, string $configKey, int $productionStart): void
    {
        $model::query()->create(['cnpj' => '12563112000130', $series => 1, 'tp_amb' => 0, 'next_number' => 900000030]);
        config(['dfe.emit.cnpj' => '12563112000130', 'dfe.cte.serie' => 1, 'dfe.mdfe.series' => 1, 'dfe.tp_amb' => '1', $configKey => $productionStart]);
        try {
            app($allocator)->handle();
            $this->fail('Sequências antigas precisam ter o ambiente confirmado.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('ambiente', $exception->getMessage());
        }
        $this->assertSame(1, $model::count());
        $this->assertSame(900000030, (int) $model::first()->next_number);
    }

    public function test_migration_preserves_legacy_numbers_as_unclassified(): void
    {
        $migration = require database_path('migrations/2026_10_09_141750_add_environment_to_fiscal_number_sequences.php');
        $migration->down();
        Schema::getConnection()->table('cte_number_sequences')->insert(['cnpj' => '12563112000130', 'serie' => 1, 'next_number' => 3070]);
        Schema::getConnection()->table('mdfe_number_sequences')->insert(['cnpj' => '12563112000130', 'series' => 1, 'next_number' => 290]);
        $migration->up();
        $this->assertDatabaseHas('cte_number_sequences', ['tp_amb' => 0, 'next_number' => 3070]);
        $this->assertDatabaseHas('mdfe_number_sequences', ['tp_amb' => 0, 'next_number' => 290]);
    }

    public function test_rollback_cannot_merge_counters_from_different_environments(): void
    {
        CteNumberSequence::query()->create(['cnpj' => '12563112000130', 'serie' => 1, 'tp_amb' => 1, 'next_number' => 3008]);
        CteNumberSequence::query()->create(['cnpj' => '12563112000130', 'serie' => 1, 'tp_amb' => 2, 'next_number' => 900000016]);
        $migration = require database_path('migrations/2026_10_09_141750_add_environment_to_fiscal_number_sequences.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_cte_production_switch_is_present_in_the_loaded_configuration(): void
    {
        $this->assertIsBool(config('dfe.cte.production_enabled'));
        $this->assertSame('5353', config('dfe.cte.cfop'));
    }
}
