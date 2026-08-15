<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Services\Cte\CteDocumentRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CteDocumentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pre_authorization_failure_can_be_requeued_explicitly(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'error_code' => 'LAB_FIELD_MISMATCH',
        ]);

        app(CteDocumentRecoveryService::class)->retry($document);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $document->refresh()->status);
        $this->assertNull($document->error_code);
        $this->assertSame(CteEmissionBatchStatusEnum::PROCESSING, $batch->refresh()->status);
    }

    public function test_retrying_failed_documents_reopens_the_batch_without_touching_authorized_or_reconciliation_documents(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        $failed = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'error_code' => 'COMError',
            'error_message' => 'Falha temporária',
            'claimed_at' => now(),
            'claim_expires_at' => now()->addMinute(),
        ]);
        $authorized = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '2707',
        ]);
        $reconciliation = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
            'error_code' => 'manual_review',
        ]);

        app(CteDocumentRecoveryService::class)->retryMany(collect([$failed, $authorized, $reconciliation]));

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $failed->refresh()->status);
        $this->assertNull($failed->error_code);
        $this->assertNull($failed->claim_expires_at);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->refresh()->status);
        $this->assertSame('2707', $authorized->cte_number);
        $this->assertSame(CteDocumentStatusEnum::RECONCILIATION_REQUIRED, $reconciliation->refresh()->status);
        $this->assertSame('manual_review', $reconciliation->error_code);
        $this->assertSame(CteEmissionBatchStatusEnum::PROCESSING, $batch->refresh()->status);
    }

    public function test_a_reconciliation_can_register_an_authorized_cte_manually(): void
    {
        $document = CteDocument::factory()->create([
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        app(CteDocumentRecoveryService::class)->reconcile($document, [
            'number' => '2670',
            'access_key' => '35260812563112000130570010000026701338262343',
            'protocol' => '135263860830097',
            'reason' => 'XML confirmado manualmente no Lab.',
        ]);

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $document->refresh()->status);
        $this->assertSame('2670', $document->cte_number);
        $this->assertNotNull($document->authorized_at);
    }

    public function test_a_document_after_the_fiscal_barrier_cannot_be_retried(): void
    {
        $document = CteDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZING,
        ]);

        $this->expectException(ValidationException::class);

        app(CteDocumentRecoveryService::class)->retry($document);
    }
}
