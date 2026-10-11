<?php

namespace Tests\Feature\Services\Dfe;

use App\Enums\CteDocumentStatusEnum;
use App\Models\City;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use App\Services\Dfe\CloseMdfeDocument;
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

class CloseMdfeDocumentTest extends TestCase
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
        ]);
        Storage::fake('dfe');
        Http::preventStrayRequests();
        $certificate = DfeTestCertificate::create();
        app()->instance(SefazCertificate::class, new SefazCertificate($certificate['path'], $certificate['password']));
        app()->instance(MdfeSoapClient::class, new MdfeSoapClient(app(SefazCertificate::class)));
    }

    public function test_registers_closure_event_and_stores_proc_event_with_hash(): void
    {
        $accessKey = '35261012563112000130580010090000021123456788';
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'access_key' => $accessKey,
            'protocol' => '135260000000021',
        ]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'cte',
            'resource_id' => 456,
            'mdfe_document_id' => $document->id,
        ]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'ciot',
            'resource_id' => 789,
            'mdfe_document_id' => $document->id,
        ]);
        $city = City::factory()->create(['ibge_code' => '4106902', 'name' => 'Curitiba', 'state' => 'PR']);
        $returnXml = '<retEventoMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00"><infEvento>'
            .'<tpAmb>2</tpAmb><verAplic>SVRS2026</verAplic><cOrgao>35</cOrgao><cStat>135</cStat>'
            .'<xMotivo>Evento registrado e vinculado</xMotivo><chMDFe>'.$accessKey.'</chMDFe>'
            .'<tpEvento>110112</tpEvento><xEvento>Encerramento</xEvento><nSeqEvento>1</nSeqEvento>'
            .'<dhRegEvento>2026-10-05T14:30:00-03:00</dhRegEvento><nProt>935260000000021</nProt>'
            .'</infEvento></retEventoMDFe>';
        Http::fake(['*' => Http::sequence()
            ->push($returnXml)
            ->push('<retConsSitMDFe><cStat>132</cStat><xMotivo>Encerrado</xMotivo></retConsSitMDFe>')]);

        $event = app(CloseMdfeDocument::class)->handle($document, $city);

        $this->assertSame('registered', $event->status);
        $this->assertSame('935260000000021', $event->protocol);
        $this->assertSame('41', $event->c_uf);
        $this->assertSame('4106902', $event->c_mun);
        $this->assertNotNull($event->xml_path);
        $this->assertTrue(Storage::disk('dfe')->exists($event->xml_path));
        $this->assertSame(hash('sha256', Storage::disk('dfe')->get($event->xml_path)), $event->sha256);
        $this->assertStringContainsString('<evEncMDFe>', Storage::disk('dfe')->get($event->xml_path));
        $this->assertSame('132', $document->refresh()->fiscal_status_code);
        $this->assertSame('closed', $document->refresh()->fiscal_status->value);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'mdfe_document_id' => $document->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'mdfe_document_id' => $document->id,
        ]);
    }

    public function test_blocks_closure_transmission_in_production_until_the_flag_is_enabled(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.mdfe.production_enabled' => false]);
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'access_key' => str_repeat('1', 44),
            'protocol' => '935260000000001',
        ]);
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('produção está bloqueada');
        app(CloseMdfeDocument::class)->handle($document, $city);
    }

    public function test_a_correctable_rejection_can_be_retried_with_the_same_event_id_and_keeps_both_attempts(): void
    {
        $accessKey = '35261012563112000130580010090000021123456788';
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'access_key' => $accessKey,
            'protocol' => '135260000000021',
        ]);
        $firstCity = City::factory()->create(['ibge_code' => '4106902', 'name' => 'Curitiba', 'state' => 'PR']);
        $correctedCity = City::factory()->create(['ibge_code' => '4115200', 'name' => 'Maringá', 'state' => 'PR']);
        $rejection = '<retEventoMDFe><infEvento><cStat>614</cStat><xMotivo>UF diverge do município</xMotivo></infEvento></retEventoMDFe>';
        $registered = '<retEventoMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00"><infEvento>'
            .'<tpAmb>2</tpAmb><verAplic>SVRS2026</verAplic><cOrgao>35</cOrgao><cStat>135</cStat>'
            .'<xMotivo>Evento registrado e vinculado</xMotivo><chMDFe>'.$accessKey.'</chMDFe>'
            .'<tpEvento>110112</tpEvento><xEvento>Encerramento</xEvento><nSeqEvento>1</nSeqEvento>'
            .'<dhRegEvento>2026-10-05T14:30:00-03:00</dhRegEvento><nProt>935260000000021</nProt>'
            .'</infEvento></retEventoMDFe>';
        Http::fake(['*' => Http::sequence()
            ->push($rejection)
            ->push($registered)
            ->push('<retConsSitMDFe><cStat>132</cStat><xMotivo>Encerrado</xMotivo></retConsSitMDFe>')]);

        $firstAttempt = app(CloseMdfeDocument::class)->handle($document, $firstCity);
        $secondAttempt = app(CloseMdfeDocument::class)->handle($document, $correctedCity);

        $this->assertSame('rejected_retryable', $firstAttempt->status);
        $this->assertSame('614', $firstAttempt->error_code);
        $this->assertSame(1, $firstAttempt->attempt);
        $this->assertSame('registered', $secondAttempt->status);
        $this->assertSame(2, $secondAttempt->attempt);
        $this->assertSame($firstAttempt->event_id, $secondAttempt->event_id);
        $this->assertSame(2, $document->events()->where('event_type', '110112')->count());
        $this->assertSame('4115200', $secondAttempt->c_mun);
    }

    public function test_statuses_134_and_136_are_recorded_as_registered_not_rejections(): void
    {
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);
        $returns = Http::sequence();

        foreach (['134', '136'] as $index => $cStat) {
            $accessKey = str_pad((string) ($index + 1), 44, '1', STR_PAD_LEFT);
            $returns->push($this->registeredEventResponse($accessKey, $cStat, '93526000000000'.($index + 1)));
            $returns->push('<retConsSitMDFe><cStat>132</cStat><xMotivo>Encerrado</xMotivo></retConsSitMDFe>');
        }

        Http::fake(['*' => $returns]);

        foreach (['134', '136'] as $index => $cStat) {
            $document = MdfeDocument::factory()->create([
                'status' => CteDocumentStatusEnum::AUTHORIZED,
                'execution_mode' => 'live',
                'emitter' => 'direct',
                'access_key' => str_pad((string) ($index + 1), 44, '1', STR_PAD_LEFT),
                'protocol' => '13526000000002'.($index + 1),
            ]);

            $event = app(CloseMdfeDocument::class)->handle($document, $city);

            $this->assertSame($cStat, $event->response_xml !== null ? $this->responseStatus($event->response_xml) : null);
            $this->assertSame('registered', $event->status);
        }
    }

    public function test_duplicate_and_already_closed_responses_are_reconciled_and_not_retried(): void
    {
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);
        $returns = Http::sequence();

        foreach (['631', '609'] as $cStat) {
            $returns->push('<retEventoMDFe><infEvento><cStat>'.$cStat.'</cStat><xMotivo>Verificar situação</xMotivo></infEvento></retEventoMDFe>');
            $returns->push('<retConsSitMDFe><cStat>132</cStat><xMotivo>Encerrado</xMotivo></retConsSitMDFe>');
        }

        Http::fake(['*' => $returns]);

        foreach (['631' => 'duplicate_reconciliation', '609' => 'already_closed'] as $cStat => $expectedStatus) {
            $document = MdfeDocument::factory()->create([
                'status' => CteDocumentStatusEnum::AUTHORIZED,
                'execution_mode' => 'live',
                'emitter' => 'direct',
                'access_key' => fake()->unique()->numerify(str_repeat('#', 44)),
                'protocol' => '135260000000021',
            ]);

            $event = app(CloseMdfeDocument::class)->handle($document, $city);

            $this->assertSame($expectedStatus, $event->status);
            $this->assertSame((string) $cStat, (string) $event->error_code);
            $this->assertSame('132', $document->refresh()->fiscal_status_code);
        }
    }

    public function test_timeout_triggers_a_consultation_and_blocks_automatic_retry(): void
    {
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'access_key' => fake()->unique()->numerify(str_repeat('#', 44)),
            'protocol' => '135260000000021',
        ]);
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);
        $client = Mockery::mock(MdfeSoapClient::class);
        $client->shouldReceive('transmitEvent')->once()->andThrow(new SefazException('Timeout de transmissão.'));
        $client->shouldReceive('consult')->once()->andReturn(new SefazResponse('100', 'MDF-e autorizado'));
        app()->instance(MdfeSoapClient::class, $client);

        try {
            app(CloseMdfeDocument::class)->handle($document, $city);
            $this->fail('Expected inconclusive transmission exception.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('inconclusivo', $exception->getMessage());
        }

        $this->assertSame('100', $document->refresh()->fiscal_status_code);
        $this->assertSame('reconciliation_required', $document->events()->firstOrFail()->status);
    }

    public function test_timeout_is_reconciled_as_closed_when_the_follow_up_consult_confirms_cstat_132(): void
    {
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'access_key' => fake()->unique()->numerify(str_repeat('#', 44)),
            'protocol' => '135260000000021',
        ]);
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);
        $client = Mockery::mock(MdfeSoapClient::class);
        $client->shouldReceive('transmitEvent')->once()->andThrow(new SefazException('Timeout de transmissão.'));
        $client->shouldReceive('consult')->once()->andReturn(new SefazResponse(
            cStat: '132',
            xMotivo: 'Encerrado',
            rawXml: '<retConsSitMDFe><cStat>132</cStat><xMotivo>Encerrado</xMotivo></retConsSitMDFe>',
            closureEventProtocol: '935260000000021',
            closureEventReceivedAt: '2026-10-07T10:00:00-03:00',
        ));
        app()->instance(MdfeSoapClient::class, $client);

        $event = app(CloseMdfeDocument::class)->handle($document, $city);

        $this->assertSame('already_closed', $event->status);
        $this->assertSame('935260000000021', $event->protocol);
        $this->assertNull($event->error_code);
        $this->assertSame('132', $document->refresh()->fiscal_status_code);
    }

    public function test_reconciles_an_interrupted_pending_event_without_retransmitting_when_sefaz_confirms_closure(): void
    {
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'access_key' => fake()->unique()->numerify(str_repeat('#', 44)),
            'protocol' => '135260000000021',
        ]);
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);
        $event = $document->events()->create([
            'event_type' => '110112',
            'sequence' => 1,
            'attempt' => 1,
            'status' => 'pending',
            'event_id' => 'ID110112'.str_repeat('1', 44).'01',
            'payload_xml' => '<eventoMDFe/>',
        ]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'cte',
            'resource_id' => 456,
            'mdfe_document_id' => $document->id,
        ]);
        $client = Mockery::mock(MdfeSoapClient::class);
        $client->shouldReceive('consult')->once()->andReturn(new SefazResponse(
            cStat: '132',
            xMotivo: 'Encerrado',
            rawXml: '<retConsSitMDFe><cStat>132</cStat><xMotivo>Encerrado</xMotivo></retConsSitMDFe>',
            closureEventProtocol: '935260000000021',
            closureEventReceivedAt: '2026-10-07T10:00:00-03:00',
        ));
        $client->shouldNotReceive('transmitEvent');
        app()->instance(MdfeSoapClient::class, $client);

        $reconciled = app(CloseMdfeDocument::class)->handle($document, $city);

        $this->assertSame($event->id, $reconciled->id);
        $this->assertSame('already_closed', $reconciled->status);
        $this->assertSame('935260000000021', $reconciled->protocol);
        $this->assertSame('132', $document->refresh()->fiscal_status_code);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'mdfe_document_id' => $document->id,
        ]);
    }

    public function test_persists_raw_transport_response_before_parsing_an_unreadable_event_reply(): void
    {
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'access_key' => fake()->unique()->numerify(str_repeat('#', 44)),
            'protocol' => '935260000000021',
        ]);
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);
        Http::fake(['*' => Http::sequence()
            ->push('proxy error: invalid SOAP action', 502, ['Content-Type' => 'text/plain'])
            ->push('<retConsSitMDFe><cStat>100</cStat><xMotivo>Autorizado</xMotivo></retConsSitMDFe>')]);

        try {
            app(CloseMdfeDocument::class)->handle($document, $city);
            $this->fail('Expected inconclusive transmission exception.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inconclusivo', $exception->getMessage());
        }

        $event = $document->events()->firstOrFail();
        $this->assertSame(502, $event->transport_http_status);
        $this->assertSame('text/plain', $event->response_content_type);
        $this->assertSame('proxy error: invalid SOAP action', $event->response_body);
        $this->assertSame('100', $document->refresh()->fiscal_status_code);
    }

    public function test_allows_one_supervised_retry_after_fresh_consult_confirms_no_registered_event(): void
    {
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'access_key' => fake()->unique()->numerify(str_repeat('#', 44)),
            'protocol' => '935260000000021',
        ]);
        $city = City::factory()->create(['ibge_code' => '4106902', 'state' => 'PR']);
        $eventRegistered = $this->registeredEventResponse((string) $document->access_key, '135', '935260000000022');
        Http::fake(['*' => Http::sequence()
            ->push('unreadable gateway response', 502, ['Content-Type' => 'text/plain'])
            ->push('<retConsSitMDFe><cStat>100</cStat><xMotivo>Autorizado</xMotivo></retConsSitMDFe>')
            ->push('<retConsSitMDFe><cStat>100</cStat><xMotivo>Autorizado</xMotivo></retConsSitMDFe>')
            ->push($eventRegistered)
            ->push('<retConsSitMDFe><cStat>132</cStat><xMotivo>Encerrado</xMotivo><procEventoMDFe/></retConsSitMDFe>')]);

        try {
            app(CloseMdfeDocument::class)->handle($document, $city);
            $this->fail('Expected inconclusive transmission exception.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inconclusivo', $exception->getMessage());
        }

        $firstAttempt = $document->events()->firstOrFail();
        $secondAttempt = app(CloseMdfeDocument::class)->handle($document, $city);

        $this->assertSame('reconciliation_required', $firstAttempt->refresh()->status);
        $this->assertSame('registered', $secondAttempt->status);
        $this->assertSame($firstAttempt->event_id, $secondAttempt->event_id);
        $this->assertSame(1, $secondAttempt->sequence);
        $this->assertSame(2, $secondAttempt->attempt);
    }

    private function registeredEventResponse(string $accessKey, string $cStat, string $protocol): string
    {
        return '<retEventoMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00"><infEvento>'
            .'<tpAmb>2</tpAmb><verAplic>SVRS2026</verAplic><cOrgao>35</cOrgao><cStat>'.$cStat.'</cStat>'
            .'<xMotivo>Evento registrado</xMotivo><chMDFe>'.$accessKey.'</chMDFe>'
            .'<tpEvento>110112</tpEvento><xEvento>Encerramento</xEvento><nSeqEvento>1</nSeqEvento>'
            .'<dhRegEvento>2026-10-05T14:30:00-03:00</dhRegEvento><nProt>'.$protocol.'</nProt>'
            .'</infEvento></retEventoMDFe>';
    }

    private function responseStatus(string $xml): ?string
    {
        return preg_match('/<cStat>([^<]+)<\/cStat>/', $xml, $matches) === 1 ? $matches[1] : null;
    }
}
