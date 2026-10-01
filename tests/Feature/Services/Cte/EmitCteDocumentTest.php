<?php

namespace Tests\Feature\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Jobs\EmitCteDirectJob;
use App\Services\Cte\EmitCteDocument;
use App\Services\Dfe\SefazException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCteWorld;
use Tests\Support\DfeSoapResponses;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class EmitCteDocumentTest extends TestCase
{
    use BuildsCteWorld;
    use RefreshDatabase;

    protected string $baseUrl = 'https://sefaz-hml.test/CTeWS/WS';

    protected function setUp(): void
    {
        parent::setUp();

        $certificate = DfeTestCertificate::create();

        config([
            'dfe.tp_amb' => '2',
            'dfe.base_url' => $this->baseUrl,
            'dfe.cert_path' => $certificate['path'],
            'dfe.cert_password' => $certificate['password'],
            'dfe.ca_bundle' => '/caminho/inexistente/sefaz-ca.pem',
            'dfe.log_channel' => 'null',
            'dfe.xsd_path' => resource_path('xsds/cte'),
        ]);

        Storage::fake('dfe');

        $this->seedCteWorld();
    }

    public function test_attempt_authorizes_the_document_and_stores_the_cte_proc(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = $this->liveDirectDocument();

        $authorized = app(EmitCteDocument::class)->attempt($document);

        $authorized = $authorized->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->status);
        $this->assertSame('100', $authorized->fiscal_status_code);
        $this->assertSame('135260007132708', $authorized->protocol);
        $this->assertSame(44, strlen((string) $authorized->access_key));
        $this->assertSame('900000001', $authorized->cte_number);
        $this->assertSame('1', $authorized->series);
        $this->assertStringContainsString('<Signature', (string) $authorized->payload_xml);
        $this->assertStringContainsString('<retCTe', (string) $authorized->response_xml);

        // Cross-check: o digVal da SEFAZ ecoou o DigestValue que assinamos.
        preg_match('/<DigestValue>([^<]*)<\/DigestValue>/', (string) $authorized->payload_xml, $matches);
        $this->assertSame($matches[1], $authorized->digest_value);

        // Arquivo fiscal de guarda no prefixo dfe/.
        $this->assertSame('cte/'.$authorized->access_key.'/cte-proc.xml', $authorized->xml_path);
        Storage::disk('dfe')->assertExists($authorized->xml_path);
        $this->assertSame(hash('sha256', (string) Storage::disk('dfe')->get($authorized->xml_path)), $authorized->sha256);

        // Lote com um único documento autorizado fecha como completed.
        $this->assertSame('completed', $authorized->batch->refresh()->status->value);
        $this->assertNotNull($authorized->batch->completed_at);
    }

    public function test_a_digval_mismatch_marks_reconciliation_required_and_skips_the_store(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl, forcedDigVal: base64_encode(str_repeat('x', 20)));

        $document = $this->liveDirectDocument();

        $document = app(EmitCteDocument::class)->attempt($document)->refresh();

        $this->assertSame(CteDocumentStatusEnum::RECONCILIATION_REQUIRED, $document->status);
        $this->assertSame('digval_mismatch', $document->error_code);
        $this->assertSame('100', $document->fiscal_status_code);
        $this->assertNotNull($document->payload_xml);
        $this->assertNull($document->xml_path);
    }

    public function test_a_content_rejection_marks_the_document_as_rejected(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl, cStat: '225', xMotivo: 'Rejeicao: Duplicidade de CT-e');

        $document = $this->liveDirectDocument();

        $document = app(EmitCteDocument::class)->attempt($document)->refresh();

        $this->assertSame(CteDocumentStatusEnum::REJECTED, $document->status);
        $this->assertSame('225', $document->fiscal_status_code);
        $this->assertStringContainsString('Duplicidade', (string) $document->fiscal_status_message);
        $this->assertNull($document->xml_path);
    }

    public function test_a_retryable_failure_leaves_the_document_authorizing_for_the_job(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        Http::fake([
            $this->baseUrl.'/CTeRecepcaoSincV4.asmx' => Http::response('boom', 503),
        ]);

        $document = $this->liveDirectDocument();

        $this->expectException(SefazException::class);

        try {
            app(EmitCteDocument::class)->attempt($document);
        } finally {
            $this->assertSame(CteDocumentStatusEnum::AUTHORIZING, $document->refresh()->status);
        }
    }

    public function test_the_job_marks_a_rule_violation_as_failed_before_authorization(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = $this->liveDirectDocument();

        // Seguradora sem cadastro: violação de regra/dados (terminal).
        $document->forceFill([
            'snapshot' => array_merge($document->snapshot, ['insurance' => 'PIER SEGURADORA']),
        ])->save();

        (new EmitCteDirectJob($document->id))->handle(app(EmitCteDocument::class));

        $document = $document->refresh();

        $this->assertSame(CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION, $document->status);
        $this->assertStringContainsString('PIER SEGURADORA', (string) $document->error_message);
    }

    public function test_a_terminal_document_is_not_retransmitted_by_the_job(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = $this->liveDirectDocument();

        $job = new EmitCteDirectJob($document->id);
        $job->handle(app(EmitCteDocument::class));

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $document->refresh()->status);

        // Segunda execução do job (retry/broker duplicado): nada transmite.
        Http::fake([
            $this->baseUrl.'/CTeRecepcaoSincV4.asmx' => Http::response('never', 200),
        ]);

        $job->handle(app(EmitCteDocument::class));

        Http::assertNothingSent();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $document->refresh()->status);
    }
}
