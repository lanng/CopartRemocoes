<?php

namespace Tests\Feature;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteDocument;
use App\Models\CteDocumentNfe;
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
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);
        $nfe = CteDocumentNfe::query()->create([
            'cte_document_id' => $document->id,
            'access_key' => str_repeat('1', 44),
            'xml_path' => 'nfe/'.str_repeat('1', 44).'/original.xml',
            'sha256' => str_repeat('a', 64),
            'extracted_data' => [],
        ]);

        app(DeleteDraftCteEmissionBatch::class)->handle($batch);

        $this->assertModelMissing($batch);
        $this->assertModelMissing($document);
        $this->assertModelMissing($nfe);
        $this->assertModelExists($register);
    }

    public function test_deleting_a_draft_batch_preserves_documents_that_are_not_drafts(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $queuedDocument = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);

        app(DeleteDraftCteEmissionBatch::class)->handle($batch);

        $this->assertModelMissing($batch);
        $this->assertModelExists($queuedDocument);
        $this->assertNull($queuedDocument->fresh()->cte_emission_batch_id);
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
