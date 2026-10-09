<?php

namespace Tests\Feature\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Jobs\EmitCteDirectJob;
use App\Models\CteDocument;
use App\Services\Cte\CreateAlcoholCteDocument;
use App\Services\Cte\QueueCteDocumentEmission;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QueueCteDocumentEmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dfe');
        Carbon::setTestNow('2026-10-07 10:00:00-03:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_queues_a_valid_archived_draft_after_commit_only_once(): void
    {
        Queue::fake();
        $document = $this->draftDocument();

        $queued = app(QueueCteDocumentEmission::class)->handle($document);
        $retried = app(QueueCteDocumentEmission::class)->handle($queued->refresh());

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $queued->status);
        $this->assertSame(CteDocumentStatusEnum::QUEUED, $retried->status);
        Queue::assertPushed(EmitCteDirectJob::class, 1);
        Queue::assertPushed(EmitCteDirectJob::class, fn (EmitCteDirectJob $job): bool => $job->cteDocumentId === $document->id
            && $job->afterCommit === true);
    }

    public function test_refuses_to_queue_when_a_snapshot_nfe_has_no_persisted_link(): void
    {
        Queue::fake();
        $document = $this->draftDocument();
        $document->nfeDocuments()->delete();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('vínculos arquivados das NF-es');

        app(QueueCteDocumentEmission::class)->handle($document);
    }

    public function test_refuses_to_queue_when_an_original_file_is_missing_or_hash_does_not_match(): void
    {
        Queue::fake();
        $document = $this->draftDocument();
        $nfeDocument = $document->nfeDocuments()->sole();
        Storage::disk('dfe')->delete($nfeDocument->xml_path);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('XML original arquivado');

        app(QueueCteDocumentEmission::class)->handle($document);
    }

    public function test_refuses_to_queue_when_the_archived_xml_hash_has_changed(): void
    {
        Queue::fake();
        $document = $this->draftDocument();
        $document->nfeDocuments()->sole()->forceFill(['sha256' => str_repeat('f', 64)])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('hash do XML original arquivado');

        app(QueueCteDocumentEmission::class)->handle($document);
    }

    public function test_refuses_to_queue_when_delivery_is_incomplete_or_prediction_is_stale(): void
    {
        Queue::fake();
        $document = $this->draftDocument();
        $document->forceFill([
            'snapshot' => array_merge($document->snapshot, ['delivery_missing_fields' => ['street']]),
        ])->save();

        try {
            app(QueueCteDocumentEmission::class)->handle($document);
            $this->fail('An incomplete physical delivery address must block queueing.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('A entrega física está incompleta', $exception->getMessage());
        }

        $document->forceFill([
            'snapshot' => array_merge($document->snapshot, [
                'delivery_missing_fields' => [],
                'dprev' => '2026-10-06',
            ]),
        ])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('previsão de entrega está vencida');

        app(QueueCteDocumentEmission::class)->handle($document);
    }

    public function test_refuses_to_queue_non_draft_documents(): void
    {
        $document = $this->draftDocument();
        $document->forceFill(['status' => CteDocumentStatusEnum::CANCELLED])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Somente um CT-e em rascunho');

        app(QueueCteDocumentEmission::class)->handle($document);
    }

    public function test_refuses_a_sao_paulo_tomador_marked_isento_in_production(): void
    {
        config(['dfe.tp_amb' => '1']);
        $document = $this->draftDocument();
        $snapshot = $document->snapshot;
        $snapshot['dest']['ie'] = 'ISENTO';
        $document->forceFill(['snapshot' => $snapshot])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tomador fiscal em SP não pode ser informado como ISENTO');

        app(QueueCteDocumentEmission::class)->handle($document);
    }

    private function draftDocument(): CteDocument
    {
        return app(CreateAlcoholCteDocument::class)->handle(
            files: [$this->file('nfe-delivery-001.xml')],
            freightValue: '450.00',
            deliveryDate: '2026-10-10',
        );
    }

    /** @return array{filename: string, xml: string} */
    private function file(string $fixture): array
    {
        return [
            'filename' => $fixture,
            'xml' => (string) file_get_contents(base_path("tests/Fixtures/Dfe/Nfe/{$fixture}")),
        ];
    }
}
