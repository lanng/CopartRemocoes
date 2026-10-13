<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Services\Cte\CteBatchStatusUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CteBatchStatusUpdaterTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_a_batch_does_not_automatically_create_an_mdfe(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::PROCESSING,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'workflow_origin' => 'controlled_v1',
        ]);

        $updatedBatch = app(CteBatchStatusUpdater::class)->handle($batch);

        $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $updatedBatch->status);
        $this->assertCount(0, $updatedBatch->mdfeDocuments);
    }
}
