<?php

namespace Tests\Feature\Services\Dfe;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Models\Ciot;
use App\Models\City;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use App\Services\Ciot\AnttCiotClient;
use App\Services\Ciot\AnttCiotException;
use App\Services\Ciot\AnttCiotResponse;
use App\Services\Ciot\CloseCiot;
use App\Services\Dfe\CloseMdfeDocument;
use App\Services\Dfe\CloseTransportOperation;
use App\Services\Dfe\MdfeSoapClient;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\SefazException;
use App\Services\Dfe\SefazResponse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class CloseTransportOperationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'dfe.tp_amb' => '2',
            'dfe.cuf' => '35',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.mdfe.xsd_path' => resource_path('xsds/mdfe'),
            'dfe.mdfe.base_url' => 'https://mdfe-homologacao.svrs.rs.gov.br',
            'dfe.mdfe.paths.evento' => '/ws/MDFeRecepcaoEvento/MDFeRecepcaoEvento.asmx',
        ]);
        Storage::fake('dfe');
        Http::preventStrayRequests();
        $certificate = DfeTestCertificate::create();
        app()->instance(SefazCertificate::class, new SefazCertificate($certificate['path'], $certificate['password']));
        app()->instance(MdfeSoapClient::class, new MdfeSoapClient(app(SefazCertificate::class)));
    }

    public function test_does_not_close_ciot_when_sefaz_result_is_not_confirmed(): void
    {
        $ciot = Ciot::factory()->issued()->create();
        $document = $this->authorizedMdfe($ciot, ['fiscal_status_code' => '100']);
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);
        $event = $document->events()->create([
            'event_type' => '110112',
            'sequence' => 1,
            'attempt' => 1,
            'status' => 'registered',
            'event_id' => 'MDFe3526101256311200013058001009000002112345678801112',
            'payload_xml' => '<eventoMDFe/>',
        ]);

        $closeMdfe = Mockery::mock(CloseMdfeDocument::class);
        $closeMdfe->shouldReceive('handle')->once()->with($document, $city)->andReturnUsing(
            function () use ($document, $event) {
                $document->forceFill(['fiscal_status_code' => '100'])->save();

                return $event;
            },
        );
        $closeCiot = Mockery::mock(CloseCiot::class);
        $closeCiot->shouldNotReceive('handle');
        app()->instance(CloseMdfeDocument::class, $closeMdfe);
        app()->instance(CloseCiot::class, $closeCiot);

        $result = app(CloseTransportOperation::class)->handle($document, $city);

        $this->assertSame('mdfe_pending', $result->status);
        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->refresh()->status);
    }

    public function test_does_not_close_ciot_when_sefaz_guard_rejects_the_mdfe_closure(): void
    {
        $ciot = Ciot::factory()->issued()->create();
        $document = $this->authorizedMdfe($ciot);
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);

        $closeMdfe = Mockery::mock(CloseMdfeDocument::class);
        $closeMdfe->shouldReceive('handle')->once()->andThrow(new DomainException('MDF-e não pode ser encerrado.'));
        $closeCiot = Mockery::mock(CloseCiot::class);
        $closeCiot->shouldNotReceive('handle');
        app()->instance(CloseMdfeDocument::class, $closeMdfe);
        app()->instance(CloseCiot::class, $closeCiot);

        try {
            app(CloseTransportOperation::class)->handle($document, $city);
            $this->fail('Expected the SEFAZ guard to stop the coordinated closure.');
        } catch (DomainException $exception) {
            $this->assertSame('MDF-e não pode ser encerrado.', $exception->getMessage());
        }

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->refresh()->status);
    }

    public function test_sefaz_confirmation_after_a_timeout_allows_ciot_closure_without_resending_the_mdfe_event(): void
    {
        $ciot = Ciot::factory()->issued()->create();
        $document = $this->authorizedMdfe($ciot);
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'cte',
            'resource_id' => 456,
            'mdfe_document_id' => $document->id,
        ]);
        Http::fake([
            'https://antt-hml.test/pefServices/api/EncerramentoOperacaoTransporte' => Http::response([
                'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
                'Codigo' => '110',
                'DataEncerramento' => '2026-10-07T10:00:00-03:00',
            ], 200),
        ]);

        $sefazClient = Mockery::mock(MdfeSoapClient::class);
        $sefazClient->shouldReceive('transmitEvent')->once()->andThrow(new SefazException('Timeout de transmissão.'));
        $sefazClient->shouldReceive('consult')->once()->andReturn(new SefazResponse(
            cStat: '132',
            xMotivo: 'Encerrado',
            rawXml: '<retConsSitMDFe><cStat>132</cStat><xMotivo>Encerrado</xMotivo></retConsSitMDFe>',
            closureEventProtocol: '935260000000021',
            closureEventReceivedAt: '2026-10-07T10:00:00-03:00',
        ));
        app()->instance(MdfeSoapClient::class, $sefazClient);

        $result = app(CloseTransportOperation::class)->handle($document, $city);

        $this->assertSame('completed', $result->status);
        $this->assertSame('132', $document->refresh()->fiscal_status_code);
        $this->assertSame('already_closed', $document->events()->firstOrFail()->status);
        $this->assertSame(CiotStatusEnum::CLOSED, $ciot->refresh()->status);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'mdfe_document_id' => $document->id,
        ]);
    }

    public function test_both_documents_already_closed_complete_without_external_calls(): void
    {
        $ciot = Ciot::factory()->issued()->create();
        $ciot->transitionFrom(CiotStatusEnum::ISSUED, CiotStatusEnum::CLOSED, fn (): array => ['closed_at' => now()]);
        $document = $this->authorizedMdfe($ciot, [
            'fiscal_status' => FiscalDocumentStatusEnum::CLOSED,
            'fiscal_status_code' => '132',
        ]);
        $closeMdfe = Mockery::mock(CloseMdfeDocument::class);
        $closeMdfe->shouldNotReceive('handle');
        $closeCiot = Mockery::mock(CloseCiot::class);
        $closeCiot->shouldNotReceive('handle');
        app()->instance(CloseMdfeDocument::class, $closeMdfe);
        app()->instance(CloseCiot::class, $closeCiot);

        $result = app(CloseTransportOperation::class)->handle($document);

        $this->assertSame('completed', $result->status);
    }

    public function test_pending_ciot_is_not_sent_to_antt_after_sefaz_closure(): void
    {
        $ciot = Ciot::factory()->create(['status' => CiotStatusEnum::PENDING]);
        $document = $this->authorizedMdfe($ciot, [
            'fiscal_status' => FiscalDocumentStatusEnum::CLOSED,
            'fiscal_status_code' => '132',
        ]);
        $closeMdfe = Mockery::mock(CloseMdfeDocument::class);
        $closeMdfe->shouldNotReceive('handle');
        $closeCiot = Mockery::mock(CloseCiot::class);
        $closeCiot->shouldNotReceive('handle');
        app()->instance(CloseMdfeDocument::class, $closeMdfe);
        app()->instance(CloseCiot::class, $closeCiot);

        $result = app(CloseTransportOperation::class)->handle($document);

        $this->assertSame('ciot_pending', $result->status);
        $this->assertSame(CiotStatusEnum::PENDING, $ciot->refresh()->status);
    }

    public function test_resumes_only_ciot_after_sefaz_closure_and_keeps_only_the_latest_failed_attempt(): void
    {
        $ciot = Ciot::factory()->issued()->create();
        $document = $this->authorizedMdfe($ciot, [
            'fiscal_status' => FiscalDocumentStatusEnum::CLOSED,
            'fiscal_status_code' => '132',
        ]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'cte',
            'resource_id' => 456,
            'mdfe_document_id' => $document->id,
        ]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $document->id,
        ]);
        $closeMdfe = Mockery::mock(CloseMdfeDocument::class);
        $closeMdfe->shouldNotReceive('handle');
        $anttClient = Mockery::mock(AnttCiotClient::class);
        $attempt = 0;
        $anttClient->shouldReceive('encerrar')->times(3)->andReturnUsing(
            function () use (&$attempt, $ciot): AnttCiotResponse {
                $attempt++;

                if ($attempt === 1) {
                    throw new AnttCiotException(
                        'Encerramento rejeitado pela ANTT.',
                        codigo: '220',
                        mensagem: 'CIOT temporariamente indisponível.',
                        httpStatus: 503,
                        body: ['Codigo' => '220'],
                    );
                }

                if ($attempt === 2) {
                    throw new AnttCiotException(
                        'Encerramento rejeitado pela ANTT.',
                        codigo: '221',
                        mensagem: 'CIOT ainda não liquidado.',
                        httpStatus: 400,
                        body: ['Codigo' => '221'],
                    );
                }

                return new AnttCiotResponse(200, [
                    'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
                    'Codigo' => '110',
                    'DataEncerramento' => '2026-10-07T10:00:00-03:00',
                ]);
            },
        );
        app()->instance(CloseMdfeDocument::class, $closeMdfe);
        app()->instance(AnttCiotClient::class, $anttClient);

        $result = app(CloseTransportOperation::class)->handle($document);

        $this->assertSame('ciot_pending', $result->status);
        $this->assertSame('220', $ciot->refresh()->response['ultima_tentativa_encerramento']['codigo']);
        $this->assertSame('inconclusivo', $ciot->response['ultima_tentativa_encerramento']['resultado']);
        $this->assertSame(1, count($ciot->response['ultima_tentativa_encerramento']['body']));

        $result = app(CloseTransportOperation::class)->handle($document);

        $this->assertSame('ciot_pending', $result->status);
        $this->assertSame('221', $ciot->refresh()->response['ultima_tentativa_encerramento']['codigo']);
        $this->assertSame('rejeitado', $ciot->response['ultima_tentativa_encerramento']['resultado']);
        $this->assertArrayNotHasKey('tentativas', $ciot->response['ultima_tentativa_encerramento']);

        $result = app(CloseTransportOperation::class)->handle($document);

        $this->assertSame('completed', $result->status);
        $this->assertSame(CiotStatusEnum::CLOSED, $ciot->refresh()->status);
        $this->assertArrayNotHasKey('ultima_tentativa_encerramento', $ciot->response);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
        ]);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'mdfe_document_id' => $document->id,
        ]);
    }

    public function test_reuses_a_ciot_closed_by_its_separate_action(): void
    {
        $ciot = Ciot::factory()->issued()->create();
        Http::fake([
            'https://antt-hml.test/pefServices/api/EncerramentoOperacaoTransporte' => Http::response([
                'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
                'Codigo' => '110',
                'DataEncerramento' => '2026-10-07T10:00:00-03:00',
            ], 200),
        ]);
        app(CloseCiot::class)->handle($ciot);

        $document = $this->authorizedMdfe($ciot->refresh(), [
            'fiscal_status' => FiscalDocumentStatusEnum::CLOSED,
            'fiscal_status_code' => '132',
        ]);
        $closeMdfe = Mockery::mock(CloseMdfeDocument::class);
        $closeMdfe->shouldNotReceive('handle');
        $closeCiot = Mockery::mock(CloseCiot::class);
        $closeCiot->shouldNotReceive('handle');
        app()->instance(CloseMdfeDocument::class, $closeMdfe);
        app()->instance(CloseCiot::class, $closeCiot);

        $result = app(CloseTransportOperation::class)->handle($document);

        $this->assertSame('completed', $result->status);
        $this->assertSame(CiotStatusEnum::CLOSED, $ciot->refresh()->status);
    }

    private function authorizedMdfe(Ciot $ciot, array $overrides = []): MdfeDocument
    {
        return MdfeDocument::factory()->create(array_merge([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'workflow_origin' => FiscalDocumentWorkflowOriginEnum::ControlledV1,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'ciot_id' => $ciot->id,
            'access_key' => fake()->unique()->numerify(str_repeat('#', 44)),
            'protocol' => '935260000000021',
            'fiscal_status_code' => '100',
        ], $overrides));
    }
}
