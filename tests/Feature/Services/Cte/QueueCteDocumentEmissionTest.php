<?php

namespace Tests\Feature\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Jobs\EmitCteDirectJob;
use App\Models\City;
use App\Models\Company;
use App\Models\CteDocument;
use App\Services\Cte\CreateAlcoholCteDocument;
use App\Services\Cte\CreateGeneralCargoCteDocument;
use App\Services\Cte\EmitCteDocument;
use App\Services\Cte\QueueCteDocumentEmission;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        $this->seedCities();
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

    public function test_queues_a_standalone_generic_cte_with_a_manually_entered_nfe_key(): void
    {
        Queue::fake();
        $document = app(CreateGeneralCargoCteDocument::class)->handle($this->generalCargoData());

        $queued = app(QueueCteDocumentEmission::class)->handle($document);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $queued->status);
        $this->assertNull($queued->cte_emission_batch_id);
        $this->assertNull($queued->register_id);
        Queue::assertPushed(EmitCteDirectJob::class, 1);
    }

    public function test_still_queues_an_existing_v4_avulso_snapshot(): void
    {
        Queue::fake();
        $document = app(CreateGeneralCargoCteDocument::class)->handle($this->generalCargoData());
        $snapshot = (array) $document->snapshot;
        $snapshot['schema_version'] = 4;
        unset($snapshot['origin_document_mode'], $snapshot['other_document']);
        $document->forceFill(['snapshot' => $snapshot])->save();

        $queued = app(QueueCteDocumentEmission::class)->handle($document);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $queued->status);
        Queue::assertPushed(EmitCteDirectJob::class, 1);
    }

    public function test_blocks_queueing_an_interstate_generic_cte_until_cfop_is_selected(): void
    {
        Queue::fake();
        $data = $this->generalCargoData();
        $data['cfop'] = '';
        $data['destination'] = ['ibge' => '3106200', 'name' => 'Belo Horizonte', 'uf' => 'MG'];
        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('escolha o CFOP de transporte');

        app(QueueCteDocumentEmission::class)->handle($document);
    }

    public function test_queues_a_production_generic_transport_starting_outside_sp(): void
    {
        Queue::fake();
        config(['dfe.tp_amb' => '1']);
        $data = $this->generalCargoData();
        $data['origin'] = ['ibge' => '3106200', 'name' => 'Belo Horizonte', 'uf' => 'MG'];
        $data['destination'] = ['ibge' => '3550308', 'name' => 'São Paulo', 'uf' => 'SP'];
        $data['cfop'] = '6932';
        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);

        $queued = app(QueueCteDocumentEmission::class)->handle($document);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $queued->status);
        Queue::assertPushed(EmitCteDirectJob::class, 1);
    }

    public function test_global_production_toggle_still_blocks_an_off_sp_generic_cte_attempt(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $data = $this->generalCargoData();
        $data['origin'] = ['ibge' => '3106200', 'name' => 'Belo Horizonte', 'uf' => 'MG'];
        $data['destination'] = ['ibge' => '3550308', 'name' => 'São Paulo', 'uf' => 'SP'];
        $data['cfop'] = '6932';
        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);
        $queued = app(QueueCteDocumentEmission::class)->handle($document);
        config(['dfe.tp_amb' => '1', 'dfe.cte.production_enabled' => false]);

        try {
            app(EmitCteDocument::class)->attempt($queued);
            $this->fail('O bloqueio global deve impedir a tentativa antes de alterar o estado do documento.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('DFE_CTE_PRODUCTION_ENABLED', $exception->getMessage());
        }

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $document->fresh()->status);
        $this->assertDatabaseCount('cte_number_sequences', 0);
        Http::assertNothingSent();
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

    /** @return array<string, mixed> */
    private function generalCargoData(): array
    {
        $remitter = $this->company('11111111111111', 'Remetente', '3504008', 'Assis');
        $recipient = $this->company('22222222222222', 'Destinatário', '3523107', 'Itaquaquecetuba');

        return [
            'origin_document_mode' => 'nfe',
            'rem_company_id' => $remitter->id,
            'dest_company_id' => $recipient->id,
            'cfop' => '5353',
            'nat_op' => 'PREST SERV TRANSPORTE',
            'taker_code' => '3',
            'ind_ietoma' => '1',
            'taker_is_transport_company' => '0',
            'nfe_keys' => '35261011111111111111550010000000011000000011',
            'nfe_xmls' => [],
            'origin' => ['ibge' => '3504008', 'name' => 'Assis', 'uf' => 'SP'],
            'destination' => ['ibge' => '3523107', 'name' => 'Itaquaquecetuba', 'uf' => 'SP'],
            'cargo' => [
                'value' => '25000.00',
                'predominant_product' => 'MAQUINAS AGRICOLAS',
                'other_category' => '',
                'gross_weight_kg' => '12000.0000',
                'measurements' => [['unit' => '01', 'measure' => 'PESO BRUTO', 'quantity' => '12000.0000']],
            ],
            'freight' => [
                'total' => '5000.00',
                'receivable' => '5000.00',
                'components' => [['name' => 'FRETE', 'value' => '5000.00']],
            ],
        ];
    }

    private function company(string $cnpj, string $name, string $ibge, string $city): Company
    {
        return Company::factory()->create([
            'cnpj' => $cnpj,
            'name' => $name,
            'ie' => '123456789',
            'street' => 'Rua das Cargas',
            'number' => '100',
            'complement' => '',
            'district' => 'Centro',
            'city' => $city,
            'state' => 'SP',
            'zipcode' => '01001000',
            'ibge_code' => $ibge,
        ]);
    }

    private function seedCities(): void
    {
        foreach ([
            ['3504008', 'Assis', 'SP'],
            ['3523107', 'Itaquaquecetuba', 'SP'],
            ['3106200', 'Belo Horizonte', 'MG'],
            ['3550308', 'São Paulo', 'SP'],
        ] as [$ibge, $name, $state]) {
            City::query()->create(['ibge_code' => $ibge, 'name' => $name, 'state' => $state]);
        }
    }
}
