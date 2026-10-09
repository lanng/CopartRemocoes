<?php

namespace Tests\Feature\Services\Dfe;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use App\Services\Dfe\BuildMdfeCancellationEvent;
use App\Services\Dfe\CancelMdfeDocument;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\XmlSigner;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class CancelMdfeDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.cuf' => '35',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.mdfe.xsd_path' => resource_path('xsds/mdfe'),
            'dfe.mdfe.base_url' => 'https://mdfe-homologacao.svrs.rs.gov.br',
            'dfe.mdfe.paths.evento' => '/ws/MDFeRecepcaoEvento/MDFeRecepcaoEvento.asmx',
            'dfe.mdfe.paths.consulta' => '/ws/MDFeConsulta/MDFeConsulta.asmx',
            'dfe.log_channel' => 'null',
        ]);
        Storage::fake('dfe');
        Http::preventStrayRequests();
        $certificate = DfeTestCertificate::create();
        app()->instance(SefazCertificate::class, new SefazCertificate($certificate['path'], $certificate['password']));
    }

    public function test_cancels_the_mdfe_without_cascading_to_its_ciots_or_ctes(): void
    {
        $ciot = Ciot::factory()->issued()->create();
        $cte = CteDocument::factory()->authorized()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
        ]);
        $document = $this->authorizedMdfe(['ciot_id' => $ciot->id]);
        $document->cteDocuments()->attach($cte->id);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'cte',
            'resource_id' => $cte->id,
            'mdfe_document_id' => $document->id,
        ]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $document->id,
        ]);
        Http::fake(['*' => Http::sequence()
            ->push($this->registeredEventResponse((string) $document->access_key, '135', '935260000000099'))
            ->push($this->mdfeConsultation('101', 'Cancelamento homologado'))]);

        $event = app(CancelMdfeDocument::class)->handle($document, 'Operação desfeita antes do início da viagem.');

        $this->assertSame('registered', $event->status);
        $this->assertSame('935260000000099', $event->protocol);
        $this->assertSame('935260000000021', $document->refresh()->protocol);
        $this->assertSame(FiscalDocumentStatusEnum::CANCELLED, $document->fiscal_status);
        $this->assertSame('101', $document->fiscal_status_code);
        $this->assertSame(200, $event->transport_http_status);
        $this->assertStringContainsString('<retEventoMDFe', (string) $event->response_body);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $cte->refresh()->status);
        $this->assertSame(FiscalDocumentStatusEnum::AUTHORIZED, $cte->refresh()->fiscal_status);
        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->refresh()->status);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'mdfe_document_id' => $document->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'mdfe_document_id' => $document->id,
        ]);
        $this->assertNotNull($event->xml_path);
        $this->assertTrue(Storage::disk('dfe')->exists($event->xml_path));
    }

    public function test_cstat_218_reconciles_an_already_cancelled_manifest_idempotently(): void
    {
        $document = $this->authorizedMdfe();
        Http::fake(['*' => Http::sequence()
            ->push('<retEventoMDFe><infEvento><cStat>218</cStat><xMotivo>MDF-e já cancelado [nProt:935260000000099][dhCanc:2026-10-07T14:45:00-03:00]</xMotivo></infEvento></retEventoMDFe>')
            ->push($this->mdfeConsultation('101', 'Cancelamento homologado'))]);

        $event = app(CancelMdfeDocument::class)->handle($document, 'Operação desfeita antes do início da viagem.');

        $this->assertSame('already_cancelled', $event->status);
        $this->assertSame('935260000000099', $event->protocol);
        $this->assertSame(FiscalDocumentStatusEnum::CANCELLED, $document->refresh()->fiscal_status);
        $this->assertCount(2, Http::recorded());
    }

    public function test_cstat_609_marks_the_manifest_closed_without_cancelling_it(): void
    {
        $document = $this->authorizedMdfe();
        Http::fake(['*' => Http::sequence()
            ->push('<retEventoMDFe><infEvento><cStat>609</cStat><xMotivo>MDF-e já encerrado [nProt:935260000000088][dhEnc:2026-10-07T14:45:00-03:00]</xMotivo></infEvento></retEventoMDFe>')
            ->push($this->mdfeConsultation('132', 'Encerrado'))]);

        $event = app(CancelMdfeDocument::class)->handle($document, 'Operação desfeita antes do início da viagem.');

        $this->assertSame('already_closed', $event->status);
        $this->assertSame(FiscalDocumentStatusEnum::CLOSED, $document->refresh()->fiscal_status);
        $this->assertSame('132', $document->fiscal_status_code);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $document->status);
    }

    public function test_production_transmission_is_blocked_until_enabled(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.mdfe.production_enabled' => false]);
        $document = $this->authorizedMdfe();

        try {
            app(CancelMdfeDocument::class)->handle($document, 'Operação desfeita antes do início da viagem.');
            $this->fail('Expected production transmission to be blocked.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('DFE_MDFE_PRODUCTION_ENABLED', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_timeout_requires_reconciliation_and_does_not_retry_automatically(): void
    {
        $document = $this->authorizedMdfe();
        Http::fake(['*' => Http::sequence()
            ->push('gateway timeout', 502, ['Content-Type' => 'text/plain'])
            ->push($this->mdfeConsultation('100', 'Autorizado'))]);

        try {
            app(CancelMdfeDocument::class)->handle($document, 'Operação desfeita antes do início da viagem.');
            $this->fail('Expected an inconclusive result.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inconclusivo', $exception->getMessage());
        }

        $event = $document->events()->firstOrFail();
        $this->assertSame('reconciliation_required', $event->status);
        $this->assertSame(502, $event->transport_http_status);
        $this->assertSame('100', $document->refresh()->fiscal_status_code);
        $this->assertCount(2, Http::recorded());
    }

    public function test_timeout_reconciles_a_confirmed_cancellation_from_the_consulted_event(): void
    {
        $document = $this->authorizedMdfe();
        $justification = 'Operação desfeita antes do início da viagem.';
        $built = app(BuildMdfeCancellationEvent::class)->handle($document, $justification);
        $signedEventXml = (new XmlSigner(app(SefazCertificate::class)))->sign(
            $built->xml,
            'infEvento',
            'http://www.portalfiscal.inf.br/mdfe',
            $built->eventId,
        );
        $eventXml = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $signedEventXml, 1) ?? $signedEventXml;
        $procEvent = '<procEventoMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00">'
            .$eventXml.$this->registeredEventResponse((string) $document->access_key, '135', '935260000000099')
            .'</procEventoMDFe>';
        $consultation = '<retConsSitMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00">'
            .'<tpAmb>2</tpAmb><verAplic>SVRS2026</verAplic><cStat>101</cStat>'
            .'<xMotivo>Cancelamento homologado</xMotivo><protMDFe versao="3.00"><infProt>'
            .'<chMDFe>'.$document->access_key.'</chMDFe><nProt>'.$document->protocol.'</nProt>'
            .'<cStat>100</cStat><xMotivo>Autorizado</xMotivo></infProt></protMDFe>'.$procEvent.'</retConsSitMDFe>';
        Http::fake(['*' => Http::sequence()
            ->push('gateway timeout', 502, ['Content-Type' => 'text/plain'])
            ->push($consultation)]);

        $event = app(CancelMdfeDocument::class)->handle($document, $justification);

        $this->assertSame('registered', $event->status, (string) $event->error_message);
        $this->assertSame('935260000000099', $event->protocol);
        $this->assertSame(FiscalDocumentStatusEnum::CANCELLED, $document->refresh()->fiscal_status);
        $this->assertNotNull($event->xml_path);
        $this->assertTrue(Storage::disk('dfe')->exists($event->xml_path));
        $this->assertCount(2, Http::recorded());
    }

    private function authorizedMdfe(array $attributes = []): MdfeDocument
    {
        return MdfeDocument::factory()->create(array_merge([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'access_key' => '35261012563112000130580010090000021123456788',
            'protocol' => '935260000000021',
            'mdfe_number' => '900001',
        ], $attributes));
    }

    private function registeredEventResponse(string $accessKey, string $cStat, string $protocol): string
    {
        return '<retEventoMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00"><infEvento>'
            .'<tpAmb>2</tpAmb><verAplic>SVRS2026</verAplic><cOrgao>35</cOrgao><cStat>'.$cStat.'</cStat>'
            .'<xMotivo>Evento registrado e vinculado</xMotivo><chMDFe>'.$accessKey.'</chMDFe>'
            .'<tpEvento>110111</tpEvento><xEvento>Cancelamento</xEvento><nSeqEvento>1</nSeqEvento>'
            .'<dhRegEvento>2026-10-07T14:45:00-03:00</dhRegEvento><nProt>'.$protocol.'</nProt>'
            .'</infEvento></retEventoMDFe>';
    }

    private function mdfeConsultation(string $cStat, string $reason): string
    {
        return '<retConsSitMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00">'
            .'<tpAmb>2</tpAmb><verAplic>SVRS2026</verAplic><cStat>'.$cStat.'</cStat>'
            .'<xMotivo>'.$reason.'</xMotivo><protMDFe versao="3.00"><infProt>'
            .'<chMDFe>35261012563112000130580010090000021123456788</chMDFe><nProt>935260000000021</nProt>'
            .'<cStat>100</cStat><xMotivo>Autorizado</xMotivo></infProt></protMDFe></retConsSitMDFe>';
    }
}
