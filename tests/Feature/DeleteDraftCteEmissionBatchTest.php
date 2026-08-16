<?php

namespace Tests\Feature;

use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Services\Cte\DeleteDraftCteEmissionBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeleteDraftCteEmissionBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_batch_can_be_deleted_without_deleting_its_registers(): void
    {
        $register = Register::factory()->create();
        $batch = CteEmissionBatch::factory()->create();
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
        ]);

        app(DeleteDraftCteEmissionBatch::class)->handle($batch);

        $this->assertModelMissing($batch);
        $this->assertModelMissing($document);
        $this->assertModelExists($register);
    }

    public function test_an_approved_batch_cannot_be_deleted(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);

        $this->expectException(ValidationException::class);

        app(DeleteDraftCteEmissionBatch::class)->handle($batch);
    }
}
