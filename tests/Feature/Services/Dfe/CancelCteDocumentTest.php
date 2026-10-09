<?php

namespace Tests\Feature\Services\Dfe;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\CteDocument;
use App\Models\MdfeDocument;
use App\Services\Dfe\BuildCteCancellationEvent;
use App\Services\Dfe\CancelCteDocument;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\SefazResponse;
use App\Services\Dfe\XmlSigner;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class CancelCteDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.cuf' => '35',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.xsd_path' => resource_path('xsds/cte'),
            'dfe.base_url' => 'https://sefaz-hml.test/CTeWS/WS',
            'dfe.paths.evento' => 'CTeRecepcaoEventoV4.asmx',
            'dfe.paths.consulta' => 'CTeConsultaV4.asmx',
            'dfe.log_channel' => 'null',
        ]);
        Storage::fake('dfe');
        Http::preventStrayRequests();
        $certificate = DfeTestCertificate::create();
        app()->instance(SefazCertificate::class, new SefazCertificate($certificate['path'], $certificate['password']));
    }

    public function test_registers_cancel_event_and_keeps_authorization_protocol_separate(): void
    {
        $document = $this->authorizedCte();
        Http::fake(['*' => Http::sequence()
            ->push($this->registeredEventResponse((string) $document->access_key, '135', '135260000000099'))
            ->push($this->cteConsultation('101', 'Cancelamento homologado'))]);

        $event = app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');

        $this->assertSame('registered', $event->status);
        $this->assertSame('135260000000099', $event->protocol);
        $this->assertSame('135260000000021', $document->refresh()->protocol);
        $this->assertSame(CteDocumentStatusEnum::CANCELLED, $document->status);
        $this->assertSame(FiscalDocumentStatusEnum::CANCELLED, $document->fiscal_status);
        $this->assertSame('101', $document->fiscal_status_code);
        $this->assertSame('110111', $event->event_type);
        $this->assertSame(200, $event->transport_http_status);
        $this->assertStringContainsString('<retEventoCTe', (string) $event->response_body);
        $this->assertNotNull($event->xml_path);
        $this->assertTrue(Storage::disk('dfe')->exists($event->xml_path));
        $this->assertSame(hash('sha256', Storage::disk('dfe')->get($event->xml_path)), $event->sha256);
        $this->assertSame('2026-10-07', $event->event_date?->toDateString());
    }

    public function test_blocks_cancellation_while_any_linked_authorized_or_closed_mdfe_exists(): void
    {
        $document = $this->authorizedCte();
        $openMdfe = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
        ]);
        $closedMdfe = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::CLOSED,
            'fiscal_status_code' => '132',
        ]);
        $openMdfe->cteDocuments()->attach($document->id);
        $closedMdfe->cteDocuments()->attach($document->id);

        try {
            app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');
            $this->fail('Expected an active MDF-e reference to block cancellation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('MDF-e', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_cancellation_is_idempotent_when_sefaz_returns_218(): void
    {
        $document = $this->authorizedCte();
        Http::fake(['*' => Http::sequence()
            ->push('<retEventoCTe><infEvento><cStat>218</cStat><xMotivo>CT-e já cancelado [nProt:135260000000099][dhCanc:2026-10-07T14:45:00-03:00]</xMotivo></infEvento></retEventoCTe>')
            ->push($this->cteConsultation('101', 'Cancelamento homologado'))]);

        $event = app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');

        $this->assertSame('already_cancelled', $event->status);
        $this->assertSame('135260000000099', $event->protocol);
        $this->assertSame(CteDocumentStatusEnum::CANCELLED, $document->refresh()->status);
        $this->assertSame(FiscalDocumentStatusEnum::CANCELLED, $document->refresh()->fiscal_status);
        $this->assertCount(2, Http::recorded());

        try {
            app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');
            $this->fail('A confirmed cancellation must not be submitted again.');
        } catch (DomainException) {
            $this->assertCount(2, Http::recorded());
        }
    }

    public function test_terminal_rejection_is_not_retransmitted(): void
    {
        $document = $this->authorizedCte();
        Http::fake(['*' => '<retEventoCTe><infEvento><cStat>220</cStat><xMotivo>Prazo de cancelamento expirado</xMotivo></infEvento></retEventoCTe>']);

        $event = app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');

        $this->assertSame('rejected', $event->status);
        $this->assertSame('220', $event->error_code);

        try {
            app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');
            $this->fail('A terminal SEFAZ rejection must not be sent again.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('terminal', $exception->getMessage());
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_production_transmission_is_blocked_until_enabled(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.cte.production_enabled' => false]);
        $document = $this->authorizedCte();

        try {
            app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');
            $this->fail('Expected production transmission to be blocked.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('DFE_CTE_PRODUCTION_ENABLED', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_timeout_requires_reconciliation_and_does_not_retry_automatically(): void
    {
        $document = $this->authorizedCte();
        Http::fake(['*' => Http::sequence()
            ->push('gateway timeout', 502, ['Content-Type' => 'text/plain'])
            ->push($this->cteConsultation('100', 'Autorizado o uso do CT-e'))]);

        try {
            app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');
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

    public function test_timeout_reconciles_a_confirmed_cancellation_and_archives_the_consulted_event(): void
    {
        $document = $this->authorizedCte();
        $justification = 'Cancelamento da operação confirmado com o cliente.';
        $built = app(BuildCteCancellationEvent::class)->handle($document, $justification);
        $signedEventXml = (new XmlSigner(app(SefazCertificate::class)))->sign(
            $built->xml,
            'infEvento',
            'http://www.portalfiscal.inf.br/cte',
            $built->eventId,
        );
        $eventXml = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $signedEventXml, 1) ?? $signedEventXml;
        $procEvent = '<procEventoCTe xmlns="http://www.portalfiscal.inf.br/cte" versao="4.00">'
            .$eventXml.$this->registeredEventResponse((string) $document->access_key, '135', '135260000000099')
            .'</procEventoCTe>';
        $consultation = '<retConsSitCTe xmlns="http://www.portalfiscal.inf.br/cte" versao="4.00">'
            .'<tpAmb>2</tpAmb><verAplic>SP-CTe-2026-10-07-1</verAplic><cStat>101</cStat>'
            .'<xMotivo>Cancelamento homologado</xMotivo><cUF>35</cUF>'.$procEvent.'</retConsSitCTe>';
        $parsedConsultation = SefazResponse::fromSoapXml($consultation);
        $this->assertSame('101', $parsedConsultation->cStat);
        $this->assertTrue($parsedConsultation->hasCteCancellationEvent);
        $this->assertNotNull($parsedConsultation->cancellationEventXml);
        Http::fake(['*' => Http::sequence()
            ->push('gateway timeout', 502, ['Content-Type' => 'text/plain'])
            ->push($consultation)]);

        $event = app(CancelCteDocument::class)->handle($document, $justification);

        $this->assertSame('registered', $event->status, (string) $event->error_message);
        $this->assertSame('135260000000099', $event->protocol);
        $this->assertSame(CteDocumentStatusEnum::CANCELLED, $document->refresh()->status);
        $this->assertNotNull($event->xml_path);
        $this->assertTrue(Storage::disk('dfe')->exists($event->xml_path));
        $this->assertCount(2, Http::recorded());
    }

    public function test_a_later_explicit_action_retries_only_after_a_fresh_consult_confirms_authorization(): void
    {
        $document = $this->authorizedCte();
        Http::fake(['*' => Http::sequence()
            ->push('gateway timeout', 502, ['Content-Type' => 'text/plain'])
            ->push($this->cteConsultation('100', 'Autorizado o uso do CT-e'))
            ->push($this->cteConsultation('100', 'Autorizado o uso do CT-e'))
            ->push($this->registeredEventResponse((string) $document->access_key, '135', '135260000000099'))
            ->push($this->cteConsultation('101', 'Cancelamento homologado'))]);

        try {
            app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');
            $this->fail('Expected the first result to require reconciliation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inconclusivo', $exception->getMessage());
        }

        $firstAttempt = $document->events()->firstOrFail();
        $secondAttempt = app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');

        $this->assertSame('reconciliation_required', $firstAttempt->refresh()->status);
        $this->assertSame('registered', $secondAttempt->status);
        $this->assertSame($firstAttempt->event_id, $secondAttempt->event_id);
        $this->assertSame(2, $secondAttempt->attempt);
        $this->assertCount(5, Http::recorded());
    }

    public function test_pending_cancellation_is_reconciled_before_an_active_mdfe_can_block_a_retry(): void
    {
        $document = $this->authorizedCte();
        Http::fake(['*' => Http::sequence()
            ->push('gateway timeout', 502, ['Content-Type' => 'text/plain'])
            ->push($this->cteConsultation('100', 'Autorizado o uso do CT-e'))
            ->push($this->cteConsultation('100', 'Autorizado o uso do CT-e'))]);

        try {
            app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');
            $this->fail('Expected the first result to require reconciliation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inconclusivo', $exception->getMessage());
        }

        $mdfe = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
        ]);
        $mdfe->cteDocuments()->attach($document->id);

        try {
            app(CancelCteDocument::class)->handle($document, 'Cancelamento da operação confirmado com o cliente.');
            $this->fail('An active MDF-e must prevent a retry after reconciliation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('MDF-e', $exception->getMessage());
        }

        $this->assertSame(1, $document->events()->where('event_type', '110111')->count());
        $this->assertCount(3, Http::recorded());
    }

    private function authorizedCte(): CteDocument
    {
        return CteDocument::factory()->authorized()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'execution_mode' => 'live',
            'access_key' => '35261012563112000130570010090000021123456788',
            'protocol' => '135260000000021',
            'cte_number' => '900001',
        ]);
    }

    private function registeredEventResponse(string $accessKey, string $cStat, string $protocol): string
    {
        return '<retEventoCTe xmlns="http://www.portalfiscal.inf.br/cte" versao="4.00"><infEvento>'
            .'<tpAmb>2</tpAmb><verAplic>SP-CTe-2026-10-07-1</verAplic><cOrgao>35</cOrgao><cStat>'.$cStat.'</cStat>'
            .'<xMotivo>Evento registrado e vinculado</xMotivo><chCTe>'.$accessKey.'</chCTe>'
            .'<tpEvento>110111</tpEvento><xEvento>Cancelamento</xEvento><nSeqEvento>1</nSeqEvento>'
            .'<dhRegEvento>2026-10-07T14:45:00-03:00</dhRegEvento><nProt>'.$protocol.'</nProt>'
            .'</infEvento></retEventoCTe>';
    }

    private function cteConsultation(string $cStat, string $reason): string
    {
        return '<retConsSitCTe xmlns="http://www.portalfiscal.inf.br/cte" versao="4.00">'
            .'<tpAmb>2</tpAmb><verAplic>SP-CTe-2026-10-07-1</verAplic><cStat>'.$cStat.'</cStat>'
            .'<xMotivo>'.$reason.'</xMotivo><cUF>35</cUF><protCTe versao="4.00"><infProt>'
            .'<chCTe>35261012563112000130570010090000021123456788</chCTe><nProt>135260000000021</nProt>'
            .'<cStat>100</cStat><xMotivo>Autorizado o uso do CT-e</xMotivo></infProt></protCTe>'
            .'</retConsSitCTe>';
    }
}
