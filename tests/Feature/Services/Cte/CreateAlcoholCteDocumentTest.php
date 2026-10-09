<?php

namespace Tests\Feature\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Models\CteDocument;
use App\Models\User;
use App\Services\Cte\CreateAlcoholCteDocument;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreateAlcoholCteDocumentTest extends TestCase
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

    public function test_creates_a_controlled_alcohol_draft_with_multiple_archived_nfes_and_no_batch(): void
    {
        $operator = User::factory()->create();

        $document = app(CreateAlcoholCteDocument::class)->handle(
            files: [
                $this->file('nfe-delivery-001.xml', 'entrada-1.xml'),
                $this->file('nfe-delivery-002.xml', 'entrada-2.xml'),
            ],
            freightValue: '450.00',
            operator: $operator,
            deliveryDate: '2026-10-10',
        );

        $this->assertInstanceOf(CteDocument::class, $document);
        $this->assertSame(CteDocumentStatusEnum::DRAFT, $document->status);
        $this->assertNull($document->cte_emission_batch_id);
        $this->assertNull($document->register_id);
        $this->assertSame('direct', $document->emitter);
        $this->assertSame('live', $document->execution_mode);
        $this->assertSame(FiscalDocumentStatusEnum::NOT_AUTHORIZED, $document->fiscal_status);
        $this->assertSame(FiscalDocumentWorkflowOriginEnum::ControlledV1, $document->workflow_origin);

        $snapshot = $document->snapshot;
        $this->assertSame(3, $snapshot['schema_version']);
        $this->assertSame('tank_alcohol', $snapshot['line']);
        $this->assertSame(config('dfe.cte.modal'), $snapshot['modal']);
        $this->assertSame('11111111111111', $snapshot['rem']['cnpj']);
        $this->assertSame('22222222222222', $snapshot['dest']['cnpj']);
        $this->assertSame('33333333333333', $snapshot['delivery_party']['cnpj']);
        $this->assertSame('3506003', $snapshot['destination']['ibge']);
        $this->assertSame('3000.00', $snapshot['cargo_value']);
        $this->assertSame('300.0000', $snapshot['cargo_liters']);
        $this->assertSame('240.000', $snapshot['cargo_weight_kg']);
        $this->assertSame('450.00', $snapshot['value']);
        $this->assertSame('2026-10-10', $snapshot['dprev']);
        $this->assertSame(['entrada-1.xml', 'entrada-2.xml'], $snapshot['source_files']);
        $this->assertCount(2, $snapshot['nfe_documents']);
        $this->assertSame([
            '35261011111111111111550010000000011000000011',
            '35261011111111111111550010000000021000000027',
        ], array_column($snapshot['nfe_documents'], 'access_key'));

        $linkedNfes = $document->nfeDocuments()->orderBy('id')->get();
        $this->assertCount(2, $linkedNfes);

        foreach ($linkedNfes as $nfe) {
            $this->assertSame($document->id, $nfe->cte_document_id);
            $this->assertSame(hash('sha256', Storage::disk('dfe')->get($nfe->xml_path)), $nfe->sha256);
            Storage::disk('dfe')->assertExists($nfe->xml_path);
        }
    }

    public function test_defaults_delivery_date_to_the_day_after_creation(): void
    {
        $document = app(CreateAlcoholCteDocument::class)->handle(
            files: [$this->file('nfe-recipient-001.xml', 'recipient.xml')],
            freightValue: '125.00',
        );

        $this->assertSame('2026-10-08', $document->snapshot['dprev']);
    }

    public function test_default_delivery_date_uses_the_sao_paulo_emission_day_near_utc_midnight(): void
    {
        Carbon::setTestNow('2026-10-08 01:30:00 UTC');

        $document = app(CreateAlcoholCteDocument::class)->handle(
            files: [$this->file('nfe-recipient-001.xml', 'recipient.xml')],
            freightValue: '125.00',
        );

        $this->assertSame('2026-10-08', $document->snapshot['dprev']);
    }

    public function test_records_operator_delivery_complement_without_changing_original_xml(): void
    {
        $operator = User::factory()->create();
        $file = $this->file('nfe-delivery-001.xml', 'delivery.xml');
        $file['xml'] = str_replace('<xLgr>Rua da Entrega</xLgr>', '<xLgr></xLgr>', $file['xml']);
        $file['operator_complements'] = ['delivery' => ['street' => 'Rua complementada pelo operador']];

        $document = app(CreateAlcoholCteDocument::class)->handle(
            files: [$file],
            freightValue: '125.00',
            operator: $operator,
        );

        $nfeDocument = $document->nfeDocuments()->sole();
        $this->assertSame([], $document->snapshot['delivery_missing_fields']);
        $this->assertSame('Rua complementada pelo operador', $document->snapshot['delivery_party']['xLgr']);
        $this->assertSame('Rua complementada pelo operador', $document->snapshot['delivery_candidates'][0]['delivery']['street']);
        $this->assertSame($operator->id, $nfeDocument->operator_complements_user_id);
        $this->assertSame($file['xml'], Storage::disk('dfe')->get($nfeDocument->xml_path));
        $this->assertStringContainsString('<xLgr></xLgr>', Storage::disk('dfe')->get($nfeDocument->xml_path));
    }

    public function test_rejects_operator_complement_that_would_replace_a_source_value(): void
    {
        $file = $this->file('nfe-delivery-001.xml', 'delivery.xml');
        $file['operator_complements'] = ['delivery' => ['street' => 'Rua diferente']];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não pode substituir um dado informado no XML original');

        app(CreateAlcoholCteDocument::class)->handle(
            files: [$file],
            freightValue: '125.00',
            operator: User::factory()->create(),
        );
    }

    public function test_rejects_complement_that_would_replace_an_invalid_non_empty_source_value(): void
    {
        $file = $this->file('nfe-delivery-001.xml', 'delivery.xml');
        $file['xml'] = str_replace('<CNPJ>33333333333333</CNPJ>', '<CNPJ>3333333333333</CNPJ>', $file['xml']);
        $file['operator_complements'] = ['delivery' => ['cnpj' => '44444444444444']];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não pode substituir um dado informado no XML original');

        app(CreateAlcoholCteDocument::class)->handle(
            files: [$file],
            freightValue: '125.00',
            operator: User::factory()->create(),
        );
    }

    public function test_rejects_overlong_operator_delivery_complements(): void
    {
        $file = $this->file('nfe-delivery-001.xml', 'delivery.xml');
        $file['xml'] = str_replace('<xLgr>Rua da Entrega</xLgr>', '<xLgr></xLgr>', $file['xml']);
        $file['operator_complements'] = ['delivery' => ['street' => str_repeat('R', 256)]];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('excede o limite de 255 caracteres');

        app(CreateAlcoholCteDocument::class)->handle(
            files: [$file],
            freightValue: '125.00',
            operator: User::factory()->create(),
        );
    }

    public function test_warns_when_an_nfe_is_already_linked_to_another_cte_without_blocking_the_draft(): void
    {
        $service = app(CreateAlcoholCteDocument::class);
        $file = $this->file('nfe-recipient-001.xml', 'same-nfe.xml');
        $previous = $service->handle([$file], '125.00');

        $document = $service->handle([$file], '125.00');

        $this->assertSame(CteDocumentStatusEnum::DRAFT, $document->status);
        $this->assertCount(1, $document->snapshot['reuse_warnings']);
        $this->assertSame($previous->id, $document->snapshot['reuse_warnings'][0]['cte_document_id']);
        $this->assertSame('draft', $document->snapshot['reuse_warnings'][0]['status']);
    }

    public function test_warns_when_a_legacy_singular_snapshot_contains_the_nfe_key(): void
    {
        $file = $this->file('nfe-recipient-001.xml', 'same-nfe.xml');
        $accessKey = app(\App\Services\Cte\ImportNfeXml::class)->handle($file['xml'], $file['filename'])['access_key'];
        $previous = CteDocument::factory()->authorized()->create([
            'snapshot' => ['schema_version' => 2, 'nfe_key' => $accessKey],
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
        ]);

        $document = app(CreateAlcoholCteDocument::class)->handle([$file], '125.00');

        $this->assertCount(1, $document->snapshot['reuse_warnings']);
        $this->assertSame($accessKey, $document->snapshot['reuse_warnings'][0]['access_key']);
        $this->assertSame($previous->id, $document->snapshot['reuse_warnings'][0]['cte_document_id']);
        $this->assertSame('authorized', $document->snapshot['reuse_warnings'][0]['status']);
        $this->assertSame('authorized', $document->snapshot['reuse_warnings'][0]['fiscal_status']);
    }

    public function test_rejects_non_positive_freight_and_invalid_delivery_date(): void
    {
        try {
            app(CreateAlcoholCteDocument::class)->handle(
                files: [$this->file('nfe-recipient-001.xml', 'recipient.xml')],
                freightValue: '0.00',
            );
            $this->fail('A non-positive freight value must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('maior que zero', $exception->getMessage());
        }

        try {
            app(CreateAlcoholCteDocument::class)->handle(
                files: [$this->file('nfe-recipient-001.xml', 'recipient.xml')],
                freightValue: 'frete inválido',
            );
            $this->fail('A non-numeric freight value must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('valor monetário', $exception->getMessage());
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('formato AAAA-MM-DD');

        app(CreateAlcoholCteDocument::class)->handle(
            files: [$this->file('nfe-recipient-001.xml', 'recipient.xml')],
            freightValue: '125.00',
            deliveryDate: '08/10/2026',
        );
    }

    /** @return array{filename: string, xml: string} */
    private function file(string $fixture, string $filename): array
    {
        return [
            'filename' => $filename,
            'xml' => (string) file_get_contents(base_path("tests/Fixtures/Dfe/Nfe/{$fixture}")),
        ];
    }
}
