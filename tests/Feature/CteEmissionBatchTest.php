<?php

namespace Tests\Feature;

use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CteEmissionBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sums_fipe_and_transport_values_from_document_snapshots(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $firstRegister = Register::factory()->create([
            'fipe_value' => '100.50',
            'value' => '10.25',
        ]);
        $secondRegister = Register::factory()->create([
            'fipe_value' => '200.75',
            'value' => '20.50',
        ]);

        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $firstRegister->id,
            'snapshot' => [
                'fipe_value' => '100.50',
                'value' => '10.25',
            ],
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $secondRegister->id,
            'snapshot' => [
                'fipe_value' => '200.75',
                'value' => '20.50',
            ],
        ]);

        $firstRegister->update([
            'fipe_value' => '999.99',
            'value' => '999.99',
        ]);

        $this->assertSame(30125, $batch->totalCargoValueInCents());
        $this->assertSame(3075, $batch->totalTransportValueInCents());
    }

    public function test_an_empty_batch_has_zero_financial_totals(): void
    {
        $batch = CteEmissionBatch::factory()->create();

        $this->assertSame(0, $batch->totalCargoValueInCents());
        $this->assertSame(0, $batch->totalTransportValueInCents());
    }
}
