<?php

namespace Tests\Feature\Filament;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Filament\Resources\CteEmissionResource;
use App\Filament\Resources\CteEmissionResource\Pages\CreateCteEmission;
use App\Filament\Resources\CteEmissionResource\Pages\ListCteEmissions;
use App\Filament\Resources\CteEmissionResource\Pages\ViewCteEmission;
use App\Jobs\EmitCteDirectJob;
use App\Models\CteDocument;
use App\Models\MdfeDocument;
use App\Models\User;
use App\Services\Cte\CreateAlcoholCteDocument;
use App\Services\Cte\QueueCteDocumentEmission;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CteEmissionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        Storage::fake('dfe');
    }

    public function test_lists_emissions_from_both_emitters(): void
    {
        $direct = CteDocument::factory()->authorized()->create(['emitter' => 'direct']);
        $lab = CteDocument::factory()->authorized()->create(['emitter' => 'lab']);

        Livewire::test(ListCteEmissions::class)
            ->assertCanSeeTableRecords([$direct, $lab]);
    }

    public function test_empty_emission_list_still_offers_to_create_a_cte(): void
    {
        Livewire::test(ListCteEmissions::class)
            ->assertCanSeeTableRecords([])
            ->assertSee('Novo CT-e de álcool');
    }

    public function test_download_actions_only_appear_when_the_file_exists(): void
    {
        $withoutFiles = CteDocument::factory()->authorized()->create(['emitter' => 'direct']);

        Livewire::test(ListCteEmissions::class)
            ->assertTableActionHidden('downloadXml', $withoutFiles)
            ->assertTableActionHidden('downloadPdf', $withoutFiles);

        $accessKey = str_repeat('3', 44);
        Storage::disk('dfe')->put("cte/{$accessKey}/cte-proc.xml", '<cteProc/>');
        Storage::disk('dfe')->put("cte/{$accessKey}/dacte.pdf", '%PDF-test');

        $withFiles = CteDocument::factory()->authorized()->create([
            'emitter' => 'direct',
            'access_key' => $accessKey,
            'xml_path' => "cte/{$accessKey}/cte-proc.xml",
            'pdf_path' => "cte/{$accessKey}/dacte.pdf",
        ]);

        Livewire::test(ListCteEmissions::class)
            ->assertTableActionVisible('downloadXml', $withFiles)
            ->assertTableActionVisible('downloadPdf', $withFiles);
    }

    public function test_legacy_host_paths_do_not_advertise_unavailable_downloads(): void
    {
        $legacy = CteDocument::factory()->authorized()->create([
            'xml_path' => 'C:\\old-host\\fiscal\\cte-proc.xml',
            'pdf_path' => '/var/lib/legacy/dacte.pdf',
        ]);

        Livewire::test(ListCteEmissions::class)
            ->assertTableActionHidden('downloadXml', $legacy)
            ->assertTableActionHidden('downloadPdf', $legacy)
            ->assertTableActionHidden('regenerateDacte', $legacy);
    }

    public function test_download_xml_action_returns_the_archived_file(): void
    {
        $accessKey = str_repeat('4', 44);
        $path = "cte/{$accessKey}/cte-proc.xml";
        Storage::disk('dfe')->put($path, '<cteProc>archived</cteProc>');
        $document = CteDocument::factory()->authorized()->create([
            'access_key' => $accessKey,
            'xml_path' => $path,
        ]);

        Livewire::test(ListCteEmissions::class)
            ->callTableAction('downloadXml', $document)
            ->assertFileDownloaded(strtolower($accessKey).'-cte.xml', '<cteProc>archived</cteProc>');
    }

    public function test_pdf_download_generates_a_missing_pdf_from_the_archived_proc(): void
    {
        $accessKey = str_repeat('5', 44);
        $path = "cte/{$accessKey}/cte-proc.xml";
        Storage::disk('dfe')->put($path, (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/cte-2962.xml'));
        $document = CteDocument::factory()->authorized()->create([
            'access_key' => $accessKey,
            'xml_path' => $path,
            'pdf_path' => null,
        ]);

        Livewire::test(ListCteEmissions::class)
            ->assertTableActionVisible('downloadPdf', $document)
            ->callTableAction('downloadPdf', $document)
            ->assertFileDownloaded(strtolower($accessKey).'-dacte.pdf');

        $document->refresh();

        $this->assertNotNull($document->pdf_path);
        Storage::disk('dfe')->assertExists($document->pdf_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('dfe')->get($document->pdf_path));
    }

    public function test_create_page_saves_multiple_uploaded_nfes_as_an_archived_draft(): void
    {
        config(['filesystems.default' => 's3']);
        $temporaryDisk = config('livewire.temporary_file_upload.disk');
        $this->assertSame('local', $temporaryDisk);
        Storage::fake($temporaryDisk);

        $files = [
            UploadedFile::fake()->createWithContent('entrada-1.xml', (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml')),
            UploadedFile::fake()->createWithContent('entrada-2.xml', (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-002.xml')),
        ];

        Livewire::test(CreateCteEmission::class)
            ->set('data.nfe_xmls', $files)
            ->fillForm([
                'freight_value' => '450.00',
                'delivery_date' => '2026-10-10',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $document = CteDocument::query()->sole();

        $this->assertSame('450.00', $document->snapshot['value']);
        $this->assertSame('2026-10-10', $document->snapshot['dprev']);
        $this->assertCount(2, $document->nfeDocuments);

        foreach ($document->nfeDocuments as $nfeDocument) {
            Storage::disk('dfe')->assertExists($nfeDocument->xml_path);
            $this->assertSame(hash('sha256', Storage::disk('dfe')->get($nfeDocument->xml_path)), $nfeDocument->sha256);
        }

        $this->assertSame([], Storage::disk($temporaryDisk)->allFiles('livewire-tmp'));
    }

    public function test_create_page_can_save_and_queue_a_cte_for_emission(): void
    {
        Queue::fake();
        $temporaryDisk = config('livewire.temporary_file_upload.disk');
        Storage::fake($temporaryDisk);

        $page = Livewire::test(CreateCteEmission::class)
            ->assertSee('Salvar e enfileirar emissão')
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent(
                'entrada.xml',
                (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml'),
            )])
            ->fillForm([
                'freight_value' => '450.00',
                'delivery_date' => '2026-10-10',
            ])
            ->call('createAndQueue')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect();

        $document = CteDocument::query()->sole();

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $document->status);
        $page->assertRedirect(CteEmissionResource::getUrl('view', ['record' => $document]));
        Queue::assertPushed(EmitCteDirectJob::class, fn (EmitCteDirectJob $job): bool => $job->cteDocumentId === $document->id);
    }

    public function test_create_page_keeps_the_draft_when_emission_cannot_be_queued(): void
    {
        Queue::fake();
        $queueService = \Mockery::mock(QueueCteDocumentEmission::class);
        $queueService->shouldReceive('handle')
            ->once()
            ->andThrow(new DomainException('A entrega física está incompleta.'));
        $this->app->instance(QueueCteDocumentEmission::class, $queueService);

        $page = Livewire::test(CreateCteEmission::class)
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent(
                'entrada.xml',
                (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml'),
            )])
            ->fillForm([
                'freight_value' => '450.00',
                'delivery_date' => '2026-10-10',
            ])
            ->call('createAndQueue')
            ->assertNotified()
            ->assertRedirect();

        $document = CteDocument::query()->sole();

        $this->assertSame(CteDocumentStatusEnum::DRAFT, $document->status);
        $page->assertRedirect(CteEmissionResource::getUrl('view', ['record' => $document]));
        Queue::assertNotPushed(EmitCteDirectJob::class);
    }

    public function test_create_page_previews_nfe_totals_and_keeps_tax_and_delivery_destinations_separate(): void
    {
        $firstNfe = str_replace(
            '<pesoL>80.000</pesoL>',
            '<pesoL>80.999</pesoL>',
            (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml'),
        );

        Livewire::test(CreateCteEmission::class)
            ->set('data.nfe_xmls', [
                UploadedFile::fake()->createWithContent('entrada-1.xml', $firstNfe),
                UploadedFile::fake()->createWithContent('entrada-2.xml', (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-002.xml')),
            ])
            ->assertSee('Resumo das NF-es')
            ->assertSee('NF-e 1')
            ->assertSee('NF-e 2')
            ->assertSee('Carga consolidada')
            ->assertSee('3.000,00')
            ->assertSee('300,0000 L')
            ->assertSee('240 kg')
            ->assertDontSee('240,999 kg')
            ->assertDontSee('241 kg')
            ->assertSee('Destinatario Fiscal Ficticio Ltda')
            ->assertSee('Recebedor Fisico Ficticio Ltda');
    }

    public function test_create_page_applies_delivery_complements_without_changing_the_original_xml(): void
    {
        $originalXml = (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml');
        $xmlWithMissingStreet = str_replace('<xLgr>Rua da Entrega</xLgr>', '<xLgr></xLgr>', $originalXml);

        Livewire::test(CreateCteEmission::class)
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent('entrada.xml', $xmlWithMissingStreet)])
            ->assertSee('Complementar dados ausentes da entrega física')
            ->assertSee('Logradouro')
            ->assertFormFieldExists('delivery_complements.street')
            ->assertFormFieldDoesNotExist('delivery_complements.ie')
            ->assertFormFieldDoesNotExist('delivery_complements.field')
            ->assertDontSee('Informação complementar')
            ->set('data.delivery_complements.street', 'Rua complementada pelo operador')
            ->fillForm([
                'freight_value' => '450.00',
                'delivery_date' => '2026-10-10',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $document = CteDocument::query()->sole();
        $nfeDocument = $document->nfeDocuments()->sole();

        $this->assertSame('Rua complementada pelo operador', $document->snapshot['delivery_party']['xLgr']);
        $this->assertSame(auth()->id(), $nfeDocument->operator_complements_user_id);
        $this->assertSame($xmlWithMissingStreet, Storage::disk('dfe')->get($nfeDocument->xml_path));
    }

    public function test_create_page_hides_delivery_complements_when_all_selected_nfes_have_complete_delivery_data(): void
    {
        Livewire::test(CreateCteEmission::class)
            ->assertFormFieldDoesNotExist('delivery_complements.street')
            ->assertDontSee('Complementar dados ausentes da entrega física')
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent(
                'entrada.xml',
                (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml'),
            )])
            ->assertSee('Campos de entrega ausentes:</strong> nenhum', false)
            ->assertDontSee('Complementar dados ausentes da entrega física')
            ->assertDontSee('Informação complementar');
    }

    public function test_create_page_removes_complements_that_are_no_longer_missing_after_xmls_change(): void
    {
        $originalXml = (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml');
        $xmlWithMissingStreet = str_replace('<xLgr>Rua da Entrega</xLgr>', '<xLgr></xLgr>', $originalXml);

        Livewire::test(CreateCteEmission::class)
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent('incompleta.xml', $xmlWithMissingStreet)])
            ->set('data.delivery_complements.street', 'Rua complementada pelo operador')
            ->assertSet('data.delivery_complements.street', 'Rua complementada pelo operador')
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent('completa.xml', $originalXml)])
            ->assertSet('data.delivery_complements', []);
    }

    public function test_discard_import_removes_only_temporary_uploads(): void
    {
        $temporaryDisk = config('livewire.temporary_file_upload.disk');
        Storage::fake($temporaryDisk);
        $temporaryFile = UploadedFile::fake()->createWithContent(
            'entrada.xml',
            (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml'),
        );

        Livewire::test(CreateCteEmission::class)
            ->set('data.nfe_xmls', [$temporaryFile])
            ->call('discardImport')
            ->assertRedirect();

        $this->assertSame([], Storage::disk($temporaryDisk)->allFiles('livewire-tmp'));
        Storage::disk('dfe')->assertDirectoryEmpty('nfe');
    }

    public function test_detail_page_downloads_only_nfes_linked_to_the_current_cte(): void
    {
        $document = $this->createDraft();
        $nfeDocument = $document->nfeDocuments()->sole();

        Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertSee('NF-es vinculadas')
            ->assertSee('Tomador fiscal')
            ->assertSee('Destinatário físico')
            ->assertSee('Campinas / SP')
            ->assertSee($nfeDocument->source_filename)
            ->callAction('downloadNfeOriginal', data: ['nfe_document_id' => $nfeDocument->id])
            ->assertFileDownloaded(
                $nfeDocument->source_filename,
                Storage::disk('dfe')->get($nfeDocument->xml_path),
            );
    }

    public function test_detail_page_regenerates_and_downloads_a_missing_dacte_without_retransmitting(): void
    {
        Queue::fake();
        $accessKey = str_repeat('6', 44);
        $path = "cte/{$accessKey}/cte-proc.xml";
        Storage::disk('dfe')->put($path, (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/cte-2962.xml'));
        $document = CteDocument::factory()->authorized()->create([
            'access_key' => $accessKey,
            'xml_path' => $path,
            'pdf_path' => null,
        ]);

        Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertActionVisible('downloadPdf')
            ->callAction('downloadPdf')
            ->assertFileDownloaded(strtolower($accessKey).'-dacte.pdf');

        $this->assertNotNull($document->refresh()->pdf_path);
        Storage::disk('dfe')->assertExists($document->pdf_path);
        Queue::assertNotPushed(EmitCteDirectJob::class);
    }

    public function test_detail_page_queues_the_draft_and_reports_processing(): void
    {
        Queue::fake();
        $document = $this->createDraft();

        Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertActionVisible('queueEmission')
            ->callAction('queueEmission')
            ->assertNotified();

        $this->assertSame('queued', $document->refresh()->status->value);
    }

    public function test_detail_page_refreshes_the_latest_sefaz_status_and_message(): void
    {
        $document = $this->createDraft();
        $page = Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertSee('wire:poll.5s="refreshDocument"', false);

        $document->forceFill([
            'status' => CteDocumentStatusEnum::REJECTED,
            'fiscal_status_code' => '539',
            'fiscal_status_message' => 'Rejeição fiscal recebida pela tela de teste.',
            'error_code' => 'sefaz_rejection',
            'error_message' => 'Detalhe de processamento recebido pela tela de teste.',
        ])->save();

        $page->call('refreshDocument')
            ->assertSee('Rejeição fiscal recebida pela tela de teste.')
            ->assertSee('Detalhe de processamento recebido pela tela de teste.')
            ->assertSee('539');
    }

    public function test_detail_page_deletes_an_unsubmitted_standalone_draft_and_preserves_original_nfe_xml(): void
    {
        $document = $this->createDraft();
        $nfeDocument = $document->nfeDocuments()->sole();

        Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertActionVisible('deleteDraft')
            ->callAction('deleteDraft')
            ->assertNotified()
            ->assertRedirect(CteEmissionResource::getUrl('index'));

        $this->assertModelMissing($document);
        $this->assertModelMissing($nfeDocument);
        Storage::disk('dfe')->assertExists($nfeDocument->xml_path);
    }

    public function test_detail_page_hides_draft_deletion_when_transmission_evidence_exists(): void
    {
        $document = $this->createDraft();
        $document->forceFill(['payload_xml' => '<CTe/>'])->save();

        Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertActionHidden('deleteDraft');
    }

    public function test_detail_page_exposes_cancel_only_for_available_ctes_and_requires_justification(): void
    {
        $document = CteDocument::factory()->authorized()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
        ]);
        $page = Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()]);

        $page->assertActionVisible('cancelCte')
            ->callAction('cancelCte')
            ->assertHasActionErrors(['justification']);

        $activeMdfe = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
        ]);
        $activeMdfe->cteDocuments()->attach($document->id);

        Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertActionHidden('cancelCte');
    }

    public function test_guest_cannot_open_the_create_or_detail_pages(): void
    {
        $document = CteDocument::factory()->authorized()->create();
        auth()->logout();

        $this->get(CteEmissionResource::getUrl('create'))->assertRedirect();
        $this->get(CteEmissionResource::getUrl('view', ['record' => $document]))->assertRedirect();
    }

    public function test_direct_cte_emissions_have_create_and_view_pages(): void
    {
        $this->assertTrue(CteEmissionResource::canCreate());
        $this->assertArrayHasKey('create', CteEmissionResource::getPages());
        $this->assertArrayHasKey('view', CteEmissionResource::getPages());
    }

    public function test_destination_and_modality_are_read_from_the_emission_snapshot(): void
    {
        CteDocument::factory()->authorized()->create([
            'snapshot' => [
                'destination' => ['ibge' => '3550308', 'name' => 'Destino fiscal snapshot', 'uf' => 'SP'],
                'modal' => '01',
            ],
        ]);

        Livewire::test(ListCteEmissions::class)
            ->assertSee('Destino fiscal snapshot')
            ->assertSee('Rodoviário');
    }

    public function test_regenerates_the_dacte_from_the_stored_proc(): void
    {
        $accessKey = str_repeat('3', 44);

        Storage::disk('dfe')->put("cte/{$accessKey}/cte-proc.xml", (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/cte-2962.xml'));

        $document = CteDocument::factory()->authorized()->create([
            'emitter' => 'direct',
            'access_key' => $accessKey,
            'xml_path' => "cte/{$accessKey}/cte-proc.xml",
        ]);

        Livewire::test(ListCteEmissions::class)
            ->callTableAction('regenerateDacte', $document);

        $document->refresh();

        $this->assertNotNull($document->pdf_path);
        Storage::disk('dfe')->assertExists($document->pdf_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('dfe')->get($document->pdf_path));
    }

    private function createDraft(): CteDocument
    {
        return app(CreateAlcoholCteDocument::class)->handle(
            files: [[
                'filename' => 'entrada.xml',
                'xml' => (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml'),
            ]],
            freightValue: '450.00',
            operator: auth()->user(),
            deliveryDate: '2026-10-10',
        );
    }
}
