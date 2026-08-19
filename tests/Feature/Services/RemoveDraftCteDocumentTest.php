<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Services\Cte\RemoveDraftCteDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RemoveDraftCteDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_a_draft_document_and_keeps_the_register(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);

        app(RemoveDraftCteDocument::class)->handle($document);

        $this->assertModelMissing($document);
        $this->assertModelExists($document->register);
        $this->assertModelMissing($batch);
    }

    public function test_it_keeps_a_draft_batch_when_other_documents_remain(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);
        $remaining = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);

        app(RemoveDraftCteDocument::class)->handle($document);

        $this->assertModelExists($batch);
        $this->assertModelExists($remaining);
    }

    public function test_it_rejects_non_draft_documents_or_batches(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::APPROVED]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);

        $this->expectException(ValidationException::class);

        app(RemoveDraftCteDocument::class)->handle($document);
    }

    public function test_it_rejects_a_non_draft_document_from_a_draft_batch(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);

        $this->expectException(ValidationException::class);

        app(RemoveDraftCteDocument::class)->handle($document);
    }
}
