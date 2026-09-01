<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteAgent;
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

    public function test_retrying_documents_reopens_the_batch_without_touching_authorized_documents(): void
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
        $this->assertSame(CteDocumentStatusEnum::QUEUED, $reconciliation->refresh()->status);
        $this->assertNull($reconciliation->error_code);
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

    public function test_a_document_stuck_in_filling_can_be_requeued(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FILLING,
            'claimed_by' => CteAgent::factory(),
            'claim_token_hash' => hash('sha256', 'token'),
            'claimed_at' => now(),
            'claim_expires_at' => now()->addMinutes(10),
        ]);

        app(CteDocumentRecoveryService::class)->retry($document);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $document->refresh()->status);
        $this->assertNull($document->claimed_by);
        $this->assertNull($document->claim_token_hash);
        $this->assertNull($document->claimed_at);
        $this->assertNull($document->claim_expires_at);
        $this->assertSame(CteEmissionBatchStatusEnum::PROCESSING, $batch->refresh()->status);
    }

    public function test_every_status_except_authorized_can_be_requeued(): void
    {
        foreach (CteDocumentStatusEnum::cases() as $status) {
            if ($status === CteDocumentStatusEnum::AUTHORIZED) {
                continue;
            }

            $document = CteDocument::factory()->create([
                'status' => $status,
                'cte_number' => '2701',
                'access_key' => '35260812563112000130570010000027011338262343',
                'protocol' => '135263860830097',
                'authorized_at' => now(),
                'error_code' => 'SOME_ERROR',
            ]);

            app(CteDocumentRecoveryService::class)->retry($document);

            $this->assertSame(CteDocumentStatusEnum::QUEUED, $document->refresh()->status, "Status {$status->value} should be requeueable.");
            $this->assertNull($document->error_code);
            $this->assertNull($document->cte_number);
            $this->assertNull($document->access_key);
            $this->assertNull($document->protocol);
            $this->assertNull($document->authorized_at);
        }
    }

    public function test_an_authorized_document_cannot_be_retried(): void
    {
        $document = CteDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '2707',
        ]);

        $this->expectException(ValidationException::class);

        app(CteDocumentRecoveryService::class)->retry($document);
    }
}
