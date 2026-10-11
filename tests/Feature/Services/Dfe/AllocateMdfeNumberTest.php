<?php

namespace Tests\Feature\Services\Dfe;

use App\Models\MdfeNumberSequence;
use App\Services\Dfe\AllocateMdfeNumber;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllocateMdfeNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.mdfe.series' => 1,
            'dfe.mdfe.numbering_start' => null,
        ]);
    }

    public function test_starts_the_homologation_sequence_and_increments_it(): void
    {
        $allocator = app(AllocateMdfeNumber::class);

        $this->assertSame(900000001, $allocator->handle());
        $this->assertSame(900000002, $allocator->handle());

        $this->assertDatabaseHas(MdfeNumberSequence::class, [
            'cnpj' => '12563112000130',
            'series' => 1,
            'next_number' => 900000003,
        ]);
    }

    public function test_honors_the_configured_start_when_creating_a_sequence(): void
    {
        config(['dfe.mdfe.numbering_start' => 2964]);

        $this->assertSame(2964, app(AllocateMdfeNumber::class)->handle());
        $this->assertSame(2965, app(AllocateMdfeNumber::class)->handle());
    }

    public function test_raises_an_existing_sequence_to_the_configured_minimum(): void
    {
        MdfeNumberSequence::query()->create([
            'cnpj' => '12563112000130',
            'series' => 1,
            'next_number' => 900000002,
        ]);
        config(['dfe.mdfe.numbering_start' => 900000016]);

        $this->assertSame(900000016, app(AllocateMdfeNumber::class)->handle());

        $this->assertDatabaseHas(MdfeNumberSequence::class, [
            'cnpj' => '12563112000130',
            'series' => 1,
            'next_number' => 900000017,
        ]);
    }

    public function test_does_not_reduce_an_existing_sequence_to_a_lower_configured_minimum(): void
    {
        MdfeNumberSequence::query()->create([
            'cnpj' => '12563112000130',
            'series' => 1,
            'next_number' => 900000020,
        ]);
        config(['dfe.mdfe.numbering_start' => 900000016]);

        $this->assertSame(900000020, app(AllocateMdfeNumber::class)->handle());

        $this->assertDatabaseHas(MdfeNumberSequence::class, [
            'cnpj' => '12563112000130',
            'series' => 1,
            'next_number' => 900000021,
        ]);
    }

    public function test_refuses_to_create_a_production_sequence_without_a_configured_start(): void
    {
        config(['dfe.tp_amb' => '1']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('DFE_MDFE_NUMBER_START');

        app(AllocateMdfeNumber::class)->handle();
    }
}
