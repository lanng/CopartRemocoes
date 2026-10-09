<?php

namespace Tests\Feature\Services\Cte;

use App\Models\CteNumberSequence;
use App\Services\Cte\AllocateCteNumber;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllocateCteNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.cte.serie' => '1',
            'dfe.numbering_start' => null,
        ]);
    }

    public function test_starts_the_homologacao_test_sequence_and_increments_atomically(): void
    {
        $allocator = app(AllocateCteNumber::class);

        $this->assertSame(900000001, $allocator->handle());
        $this->assertSame(900000002, $allocator->handle());

        $this->assertDatabaseHas(CteNumberSequence::class, [
            'cnpj' => '12563112000130',
            'serie' => 1,
            'next_number' => 900000003,
        ]);
    }

    public function test_honors_an_explicit_numbering_start_seed(): void
    {
        config(['dfe.numbering_start' => 2964]);

        $this->assertSame(2964, app(AllocateCteNumber::class)->handle());
        $this->assertSame(2965, app(AllocateCteNumber::class)->handle());
    }

    public function test_raises_an_existing_sequence_to_the_configured_minimum(): void
    {
        CteNumberSequence::query()->create([
            'cnpj' => '12563112000130',
            'serie' => 1,
            'next_number' => 900000002,
        ]);
        config(['dfe.numbering_start' => 900000016]);

        $this->assertSame(900000016, app(AllocateCteNumber::class)->handle());

        $this->assertDatabaseHas(CteNumberSequence::class, [
            'cnpj' => '12563112000130',
            'serie' => 1,
            'next_number' => 900000017,
        ]);
    }

    public function test_does_not_reduce_an_existing_sequence_to_a_lower_configured_minimum(): void
    {
        CteNumberSequence::query()->create([
            'cnpj' => '12563112000130',
            'serie' => 1,
            'next_number' => 900000020,
        ]);
        config(['dfe.numbering_start' => 900000016]);

        $this->assertSame(900000020, app(AllocateCteNumber::class)->handle());

        $this->assertDatabaseHas(CteNumberSequence::class, [
            'cnpj' => '12563112000130',
            'serie' => 1,
            'next_number' => 900000021,
        ]);
    }

    public function test_refuses_to_seed_production_without_the_manual_switch_number(): void
    {
        config(['dfe.tp_amb' => '1']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('DFE_CTE_NUMBER_START');

        app(AllocateCteNumber::class)->handle();
    }

    public function test_production_uses_the_pre_seeded_row_from_the_switch(): void
    {
        config(['dfe.tp_amb' => '1']);

        CteNumberSequence::query()->create([
            'cnpj' => '12563112000130',
            'serie' => 1,
            'tp_amb' => 1,
            'next_number' => 2964,
        ]);

        $this->assertSame(2964, app(AllocateCteNumber::class)->handle());
    }

    public function test_keeps_sequences_per_series(): void
    {
        config(['dfe.cte.serie' => '2']);

        $this->assertSame(900000001, app(AllocateCteNumber::class)->handle());

        $this->assertDatabaseHas(CteNumberSequence::class, ['serie' => 2, 'next_number' => 900000002]);
        $this->assertDatabaseMissing(CteNumberSequence::class, ['serie' => 1]);
    }
}
