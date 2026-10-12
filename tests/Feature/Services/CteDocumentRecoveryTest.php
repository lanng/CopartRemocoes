<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Jobs\EmitCteDirectJob;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Services\Cte\CteDocumentRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
            'error_code' => 'FIELD_MISMATCH',
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
            'reason' => 'XML confirmado manualmente.',
        ]);

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $document->refresh()->status);
        $this->assertSame('authorized', $document->refresh()->fiscal_status->value);
        $this->assertSame('2670', $document->cte_number);
        $this->assertNotNull($document->authorized_at);
    }

    public function test_only_known_safe_statuses_can_be_requeued(): void
    {
        foreach ([
            CteDocumentStatusEnum::DRAFT,
            CteDocumentStatusEnum::QUEUED,
            CteDocumentStatusEnum::DRY_RUN_COMPLETED,
            CteDocumentStatusEnum::REJECTED,
            CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ] as $status) {
            $document = CteDocument::factory()->create([
                'status' => $status,
                'cte_number' => '2701',
                'access_key' => $status === CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION
                    ? null
                    : '35260812563112000130570010000027011338262343',
                'payload_xml' => $status === CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION ? null : '<CTe/>',
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

    public function test_a_standalone_failed_cte_can_be_requeued_without_a_batch(): void
    {
        Queue::fake();

        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'error_code' => 'local_validation',
            'emitter' => 'direct',
            'execution_mode' => 'live',
        ]);

        app(CteDocumentRecoveryService::class)->retry($document);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $document->refresh()->status);
        $this->assertNull($document->cte_emission_batch_id);
        $this->assertNull($document->error_code);
        Queue::assertPushed(EmitCteDirectJob::class, fn (EmitCteDirectJob $job): bool => $job->afterCommit === true);
    }

    public function test_a_cte_with_a_burned_key_cannot_be_manually_requeued(): void
    {
        $document = CteDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZING,
            'access_key' => '35260812563112000130570010000027011338262343',
            'payload_xml' => '<CTe/>',
        ]);

        $this->assertFalse(CteDocumentRecoveryService::canBeRequeued($document));
        $this->assertSame(0, app(CteDocumentRecoveryService::class)->retryMany(collect([$document])));
        $this->expectException(ValidationException::class);

        app(CteDocumentRecoveryService::class)->retry($document);
    }

    public function test_a_fiscally_authorized_cte_cannot_be_requeued_even_if_workflow_status_is_inconsistent(): void
    {
        $document = CteDocument::factory()->create([
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'fiscal_status' => 'authorized',
        ]);

        $this->assertFalse(CteDocumentRecoveryService::canBeRequeued($document));
    }

    public function test_a_legacy_cte_cannot_be_retried_or_reconciled(): void
    {
        $document = CteDocument::factory()->create([
            'emitter' => 'lab',
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ]);

        $this->assertFalse(CteDocumentRecoveryService::canBeRequeued($document));
        $this->expectException(ValidationException::class);
        app(CteDocumentRecoveryService::class)->retry($document);
    }

    public function test_a_legacy_cte_cannot_be_manually_reconciled(): void
    {
        $document = CteDocument::factory()->create([
            'emitter' => 'lab',
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        $this->expectException(ValidationException::class);
        app(CteDocumentRecoveryService::class)->reconcile($document, [
            'number' => '2670',
            'access_key' => '35260812563112000130570010000026701338262343',
            'protocol' => '135263860830097',
            'reason' => 'Registro histórico do emissor antigo.',
        ]);
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
