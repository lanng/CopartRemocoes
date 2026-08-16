<?php

namespace Tests\Feature\Models;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CteDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cte_document_stores_an_immutable_fiscal_snapshot_and_relations(): void
    {
        $user = User::factory()->create();
        $register = Register::query()->create($this->registerAttributes());
        $batch = CteEmissionBatch::query()->create([
            'status' => 'draft',
            'execution_mode' => 'dry_run',
            'created_by' => $user->id,
        ]);

        $document = CteDocument::query()->create([
            'public_id' => '018f15c4-8b9b-7a7d-bf99-d680f2c78676',
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::QUEUED,
            'snapshot' => [
                'vehicle_id' => '1146609',
                'vehicle_plate' => 'ESN4A20',
                'value' => '750.00',
            ],
            'idempotency_key' => '018f15c4-8b9b-7a7d-bf99-d680f2c78677',
            'execution_mode' => 'dry_run',
        ]);

        $this->assertInstanceOf(CteEmissionBatch::class, $document->batch);
        $this->assertInstanceOf(Register::class, $document->register);
        $this->assertSame(CteDocumentStatusEnum::QUEUED, $document->status);
        $this->assertSame('750.00', $document->snapshot['value']);
        $this->assertSame('018f15c4-8b9b-7a7d-bf99-d680f2c78677', $document->idempotency_key);
    }

    public function test_cte_number_is_preserved_as_a_string_and_access_key_is_unique(): void
    {
        $user = User::factory()->create();
        $register = Register::query()->create($this->registerAttributes());
        $batch = CteEmissionBatch::query()->create([
            'status' => 'draft',
            'execution_mode' => 'live',
            'created_by' => $user->id,
        ]);

        $document = CteDocument::query()->create([
            'public_id' => '018f15c4-8b9b-7a7d-bf99-d680f2c78678',
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'snapshot' => [],
            'idempotency_key' => '018f15c4-8b9b-7a7d-bf99-d680f2c78679',
            'execution_mode' => 'live',
            'cte_number' => '002670',
            'access_key' => '35260812563112000130570010000026701338262343',
        ]);

        $this->assertSame('002670', $document->refresh()->cte_number);
        $this->expectException(QueryException::class);

        CteDocument::query()->create([
            'public_id' => '018f15c4-8b9b-7a7d-bf99-d680f2c78680',
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'snapshot' => [],
            'idempotency_key' => '018f15c4-8b9b-7a7d-bf99-d680f2c78681',
            'execution_mode' => 'live',
            'cte_number' => '002671',
            'access_key' => '35260812563112000130570010000026701338262343',
        ]);
    }

    /** @return array<string, mixed> */
    private function registerAttributes(): array
    {
        return [
            'company' => 'copart',
            'vehicle_model' => 'CIVIC',
            'vehicle_plate' => 'ESN4A20',
            'origin_city' => 'Ourinhos',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => '2026-08-01',
            'deadline_delivery' => '2026-08-05',
            'vehicle_id' => '1146609',
            'value' => '750.00',
            'status' => 'pending',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
        ];
    }
}
