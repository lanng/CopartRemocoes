<?php

namespace Tests\Feature\Filament;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Filament\Resources\CteEmissionResource;
use App\Filament\Resources\CteEmissionResource\Pages\CreateAvulsoCteEmission;
use App\Filament\Resources\CteEmissionResource\Pages\CreateCteEmission;
use App\Filament\Resources\CteEmissionResource\Pages\ListCteEmissions;
use App\Filament\Resources\CteEmissionResource\Pages\ViewCteEmission;
use App\Jobs\EmitCteDirectJob;
use App\Models\City;
use App\Models\Company;
use App\Models\CteDocument;
use App\Models\MdfeDocument;
use App\Models\User;
use App\Services\Cte\CreateAlcoholCteDocument;
use App\Services\Cte\QueueCteDocumentEmission;
use App\Services\Dfe\DfeDocumentStore;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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

        $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'America/Sao_Paulo'));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        Storage::fake('dfe');
    }

    public function test_lists_internal_and_legacy_emissions_with_clear_labels(): void
    {
        $direct = CteDocument::factory()->authorized()->create(['emitter' => 'direct']);
        $lab = CteDocument::factory()->authorized()->create(['emitter' => 'lab']);

        Livewire::test(ListCteEmissions::class)
            ->assertCanSeeTableRecords([$direct, $lab])
            ->assertSee('Sistema')
            ->assertSee('Emissor antigo');
    }

    public function test_empty_emission_list_still_offers_to_create_a_cte(): void
    {
        Livewire::test(ListCteEmissions::class)
            ->assertCanSeeTableRecords([])
            ->assertSee('Novo CT-e de álcool');
    }

    public function test_listing_exposes_the_standalone_general_cargo_cte_form(): void
    {
        Livewire::test(ListCteEmissions::class)
            ->assertSee('Novo CT-e avulso');

        $this->assertArrayHasKey('create-avulso', CteEmissionResource::getPages());
        config(['app.env' => 'local']);

        $this->get(CteEmissionResource::getUrl('create-avulso'))
            ->assertSuccessful()
            ->assertSee('Referências de NF-e')
            ->assertSee('Salvar rascunho');

        Livewire::test(CreateAvulsoCteEmission::class)
            ->assertSee('Referências de NF-e')
            ->assertSee('Salvar rascunho');
    }

    public function test_avulso_form_leaves_interstate_sp_cfop_blank_for_manual_selection(): void
    {
        City::factory()->create(['ibge_code' => '3504008', 'name' => 'Assis', 'state' => 'SP']);
        City::factory()->create(['ibge_code' => '3106200', 'name' => 'Belo Horizonte', 'state' => 'MG']);

        Livewire::test(CreateAvulsoCteEmission::class)
            ->set('data.origin.ibge', '3504008')
            ->set('data.destination.ibge', '3106200')
            ->assertSet('data.cfop_suggestion', '')
            ->assertSet('data.cfop', '');
    }

    public function test_avulso_form_warns_to_check_icms_when_origin_is_outside_sp(): void
    {
        City::factory()->create(['ibge_code' => '3504008', 'name' => 'Assis', 'state' => 'SP']);
        City::factory()->create(['ibge_code' => '3106200', 'name' => 'Belo Horizonte', 'state' => 'MG']);

        Livewire::test(CreateAvulsoCteEmission::class)
            ->set('data.origin.ibge', '3106200')
            ->assertSee('Conferência fiscal da UF de início')
            ->assertSee('Quando houver guia devida, recolha antes da viagem.')
            ->assertSee('O sistema não calcula nem emite essa guia.')
            ->set('data.origin.ibge', '3504008')
            ->assertDontSee('Conferência fiscal da UF de início');
    }

    public function test_avulso_form_prefills_blank_fields_from_nfe_xml_and_preserves_manual_edits(): void
    {
        $issuer = $this->company('11111111111111', 'Remetente Ficticio Ltda');
        $recipient = $this->company('22222222222222', 'Destinatario Fiscal Ficticio Ltda');
        $firstNfe = (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml');
        $firstNfe = str_replace('<vNF>1000.00</vNF>', '<vNF>1000.01</vNF>', $firstNfe);
        $firstNfe = str_replace('<pesoL>80.000</pesoL><pesoB>80.000</pesoB>', '<pesoL>79.000</pesoL><pesoB>80.000</pesoB>', $firstNfe);
        preg_match('/<NFe>.*?<\/NFe>/s', $firstNfe, $nfeMatch);
        $firstNfe = str_replace('<NFe>', '<NFe xmlns="http://www.portalfiscal.inf.br/nfe">', $nfeMatch[0]);

        $page = Livewire::test(CreateAvulsoCteEmission::class)
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent('entrada.xml', $firstNfe)])
            ->assertSet('data.rem_company_id', (string) $issuer->id)
            ->assertSet('data.dest_company_id', (string) $recipient->id)
            ->assertSet('data.cargo.value', '1000.01')
            ->assertSet('data.cfop', '')
            ->assertSee('22071090')
            ->assertSee('Remetente localizado no cadastro')
            ->assertDontSee('Peso bruto real (kg)');

        $measurements = array_values($page->get('data.cargo.measurements'));
        $this->assertSame('PESO BRUTO', $measurements[0]['measure']);

        $this->assertDatabaseCount('companies', 2);

        $manualChoice = $this->company('33333333333333', 'Empresa selecionada manualmente');
        $page->set('data.rem_company_id', (string) $manualChoice->id)
            ->set('data.cargo.value', '123.45')
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent(
                'outra.xml', $firstNfe,
            )])
            ->assertSet('data.rem_company_id', (string) $manualChoice->id)
            ->assertSet('data.cargo.value', '123.45');
    }

    public function test_avulso_form_switches_between_nfe_and_carta_without_retaining_incompatible_references(): void
    {
        $page = Livewire::test(CreateAvulsoCteEmission::class)
            ->set('data.nfe_keys', '35261011111111111111550010000000011000000011')
            ->set('data.carta_number', 'CARTA-10')
            ->set('data.origin_document_mode', 'other')
            ->assertSet('data.nfe_keys', '')
            ->assertSet('data.nfe_xmls', [])
            ->assertSet('data.carta_number', 'CARTA-10')
            ->assertSee('Carta de remoção')
            ->assertDontSee('Expedidor')
            ->assertDontSee('Recebedor');

        $page->set('data.origin_document_mode', 'nfe')
            ->assertSet('data.carta_number', '')
            ->assertSet('data.carta_issued_on', null)
            ->assertSet('data.carta_attachment', null)
            ->assertSee('Referências de NF-e');
    }

    public function test_avulso_form_saves_carta_mode_with_an_optional_private_attachment(): void
    {
        Queue::fake();
        City::factory()->create(['ibge_code' => '3504008', 'name' => 'Assis', 'state' => 'SP']);
        City::factory()->create(['ibge_code' => '3523107', 'name' => 'Itaquaquecetuba', 'state' => 'SP']);
        $formData = $this->generalCargoFormData();
        $formData['origin_document_mode'] = 'other';
        $formData['nfe_keys'] = '';
        $formData['carta_number'] = 'REM-2026-15';
        $formData['carta_issued_on'] = '2026-10-20';
        unset($formData['nfe_xmls']);

        $page = Livewire::test(CreateAvulsoCteEmission::class)
            ->fillForm($formData)
            ->set('data.carta_attachment', UploadedFile::fake()->createWithContent(
                'carta-remocao.pdf', "%PDF-1.4\nCarta de teste\n",
            ));
        $measurement = $formData['cargo']['measurements']['peso'];
        $component = $formData['freight']['components']['frete'];
        $measurementKey = array_key_first($page->get('data.cargo.measurements'));
        $componentKey = array_key_first($page->get('data.freight.components'));

        $page->set("data.cargo.measurements.{$measurementKey}.unit", $measurement['unit'])
            ->set("data.cargo.measurements.{$measurementKey}.measure", $measurement['measure'])
            ->set("data.cargo.measurements.{$measurementKey}.quantity", $measurement['quantity'])
            ->set("data.freight.components.{$componentKey}.name", $component['name'])
            ->set("data.freight.components.{$componentKey}.value", $component['value'])
            ->call('create')
            ->assertHasNoFormErrors();

        $document = CteDocument::query()->sole();
        $attachment = $document->snapshot['other_document']['attachment'];

        $this->assertSame('other', $document->snapshot['origin_document_mode']);
        $this->assertSame('REM-2026-15', $document->snapshot['other_document']['number']);
        $this->assertSame('2026-10-20', $document->snapshot['other_document']['issued_on']);
        $this->assertSame([], $document->nfeDocuments->all());
        Storage::disk('dfe')->assertExists($attachment['path']);
        Queue::assertNothingPushed();
    }

    public function test_avulso_form_can_create_a_company_from_the_party_select_without_losing_the_draft(): void
    {
        City::factory()->create(['ibge_code' => '3504008', 'name' => 'Assis', 'state' => 'SP']);
        City::factory()->create(['ibge_code' => '3523107', 'name' => 'Itaquaquecetuba', 'state' => 'SP']);
        $data = $this->generalCargoFormData();
        $draftValue = $data['cargo']['value'];

        $page = Livewire::test(CreateAvulsoCteEmission::class)
            ->fillForm($data)
            ->callFormComponentAction('rem_company_id', 'createOption', [
                'name' => 'Nova empresa do rascunho',
                'cnpj' => '12.345.678/0001-99',
                'ie' => '123.456.789',
                'street' => 'Rua de teste',
                'number' => '10',
                'district' => 'Centro',
                'city' => 'Assis',
                'state' => 'SP',
                'zipcode' => '19800000',
                'ibge_code' => '3504008',
            ]);

        $company = Company::query()->where('cnpj', '12345678000199')->firstOrFail();
        $page->assertHasNoFormComponentActionErrors()
            ->assertSet('data.rem_company_id', (string) $company->id)
            ->assertSet('data.cargo.value', $draftValue);
    }

    public function test_view_page_downloads_only_the_carta_attachment_saved_for_that_draft(): void
    {
        $document = CteDocument::factory()->create();
        $contents = "%PDF-1.4\nattachment\n";
        $sha256 = hash('sha256', $contents);
        $path = "cte-drafts/{$document->public_id}/carta-remocao/{$sha256}.pdf";
        Storage::disk('dfe')->put($path, $contents);
        $snapshot = (array) $document->snapshot;
        $snapshot['origin_document_mode'] = 'other';
        $snapshot['other_document'] = ['attachment' => [
            'filename' => 'carta teste.pdf',
            'path' => $path,
            'sha256' => $sha256,
            'mime_type' => 'application/pdf',
        ]];
        $document->forceFill(['snapshot' => $snapshot])->save();

        Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertActionVisible('downloadCartaAttachment')
            ->callAction('downloadCartaAttachment')
            ->assertFileDownloaded('carta teste.pdf');
    }

    public function test_avulso_form_reports_missing_nfe_weight_and_volume_without_guessing(): void
    {
        $xml = (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-001.xml');
        $xml = str_replace('<qVol>1</qVol>', '', $xml);
        $xml = str_replace('<pesoL>80.000</pesoL><pesoB>80.000</pesoB>', '<pesoL>80.000</pesoL>', $xml);

        Livewire::test(CreateAvulsoCteEmission::class)
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent('incompleta.xml', $xml)])
            ->assertSee('informe peso estimado em kg ou unidades transportadas')
            ->assertSee('volume qVol não encontrado');
    }

    public function test_avulso_form_saves_a_draft_with_units_only_and_no_weight(): void
    {
        Queue::fake();
        City::factory()->create(['ibge_code' => '3504008', 'name' => 'Assis', 'state' => 'SP']);
        City::factory()->create(['ibge_code' => '3523107', 'name' => 'Itaquaquecetuba', 'state' => 'SP']);
        $formData = $this->generalCargoFormData();

        $page = Livewire::test(CreateAvulsoCteEmission::class)->fillForm($formData);
        $measurementKey = array_key_first($page->get('data.cargo.measurements'));
        $component = $formData['freight']['components']['frete'];
        $componentKey = array_key_first($page->get('data.freight.components'));

        $page->set("data.cargo.measurements.{$measurementKey}.unit", '03')
            ->set("data.cargo.measurements.{$measurementKey}.measure", 'VEICULOS')
            ->set("data.cargo.measurements.{$measurementKey}.quantity", '3')
            ->set("data.freight.components.{$componentKey}.name", $component['name'])
            ->set("data.freight.components.{$componentKey}.value", $component['value'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $document = CteDocument::query()->sole();
        $this->assertSame('VEICULOS', $document->snapshot['cargo']['measurements'][0]['measure']);
        $this->assertArrayNotHasKey('weight_kg', $document->snapshot['cargo']);
        $this->assertArrayNotHasKey('cargo_weight_kg', $document->snapshot);
        Queue::assertNothingPushed();
    }

    public function test_avulso_form_saves_a_draft_from_a_manual_key_and_uploaded_xml_without_queueing_it(): void
    {
        Queue::fake();
        City::factory()->create(['ibge_code' => '3504008', 'name' => 'Assis', 'state' => 'SP']);
        City::factory()->create(['ibge_code' => '3523107', 'name' => 'Itaquaquecetuba', 'state' => 'SP']);
        $formData = $this->generalCargoFormData();
        $measurement = $formData['cargo']['measurements']['peso'];
        $component = $formData['freight']['components']['frete'];
        unset($formData['cargo']['measurements'], $formData['freight']['components'], $formData['nfe_xmls']);

        $page = Livewire::test(CreateAvulsoCteEmission::class)
            ->fillForm($formData)
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent(
                'second-nfe.xml',
                (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-002.xml'),
            )]);
        $measurementKey = array_key_first($page->get('data.cargo.measurements'));
        $componentKey = array_key_first($page->get('data.freight.components'));

        $page->set("data.cargo.measurements.{$measurementKey}.unit", $measurement['unit'])
            ->set("data.cargo.measurements.{$measurementKey}.measure", $measurement['measure'])
            ->set("data.cargo.measurements.{$measurementKey}.quantity", $measurement['quantity'])
            ->set("data.freight.components.{$componentKey}.name", $component['name'])
            ->set("data.freight.components.{$componentKey}.value", $component['value'])
            ->call('create');
        $page
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $document = CteDocument::query()->sole();

        $this->assertSame(CteDocumentStatusEnum::DRAFT, $document->status);
        $this->assertNull($document->cte_emission_batch_id);
        $this->assertNull($document->register_id);
        $this->assertCount(2, $document->nfeDocuments);
        $this->assertNull($document->nfeDocuments->firstWhere('access_key', '35261011111111111111550010000000011000000011')?->xml_path);
        $uploadedNfe = $document->nfeDocuments->firstWhere('access_key', '35261011111111111111550010000000021000000027');
        $this->assertNotNull($uploadedNfe?->xml_path);
        Storage::disk('dfe')->assertExists($uploadedNfe->xml_path);
        $page->assertRedirect(CteEmissionResource::getUrl('view', ['record' => $document]));
        Queue::assertNothingPushed();
    }

    public function test_avulso_form_rejects_nfe_uploads_over_the_aggregate_size_limit(): void
    {
        config(['dfe.nfe_xml_max_total_size_bytes' => 1]);
        City::factory()->create(['ibge_code' => '3504008', 'name' => 'Assis', 'state' => 'SP']);
        City::factory()->create(['ibge_code' => '3523107', 'name' => 'Itaquaquecetuba', 'state' => 'SP']);
        $formData = $this->generalCargoFormData();

        $page = Livewire::test(CreateAvulsoCteEmission::class)
            ->fillForm($formData)
            ->set('data.nfe_xmls', [UploadedFile::fake()->createWithContent(
                'entrada.xml',
                (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/Nfe/nfe-delivery-002.xml'),
            )])
            ->call('create')
            ->assertHasFormErrors()
            ->assertSee('totalizar');

        $this->assertDatabaseCount('cte_documents', 0);
    }

    public function test_avulso_detail_page_displays_the_saved_cargo_and_freight_snapshot(): void
    {
        $document = CteDocument::factory()->create([
            'snapshot' => [
                'line' => 'general_cargo',
                'origin' => ['name' => 'Assis', 'uf' => 'SP'],
                'destination' => ['name' => 'Itaquaquecetuba', 'uf' => 'SP'],
                'cfop' => '5353',
                'nat_op' => 'PREST SERV TRANSPORTE',
                'rem' => ['xNome' => 'Remetente da carga'],
                'dest' => ['xNome' => 'Destinatário da carga'],
                'taker_code' => '3',
                'cargo' => [
                    'value' => '25000.00',
                    'predominant_product' => 'MAQUINAS AGRICOLAS',
                    'other_category' => 'PECAS PESADAS',
                    'weight_kg' => '12000.0000',
                    'measurements' => [['unit' => '01', 'measure' => 'PESO BRUTO', 'quantity' => '12000.0000']],
                ],
                'freight' => [
                    'total' => '5000.00',
                    'receivable' => '4800.00',
                    'components' => [['name' => 'FRETE', 'value' => '5000.00']],
                ],
            ],
        ]);

        Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertSee('CT-e avulso')
            ->assertSee('Produto predominante')
            ->assertSee('MAQUINAS AGRICOLAS')
            ->assertSee('12000.0000')
            ->assertSee('Valor a receber');
    }

    public function test_avulso_draft_page_can_edit_cfop_and_cargo(): void
    {
        City::factory()->create(['ibge_code' => '3504008', 'name' => 'Assis', 'state' => 'SP']);
        City::factory()->create(['ibge_code' => '3523107', 'name' => 'Itaquaquecetuba', 'state' => 'SP']);
        $document = app(\App\Services\Cte\CreateGeneralCargoCteDocument::class)
            ->handle($this->generalCargoFormData());

        $data = [
            'cfop' => '5352',
            'cargo' => [
                'value' => '26000.00',
                'predominant_product' => 'VEICULOS REVISADOS',
                'other_category' => '',
                'measurements' => [
                    ['unit' => '01', 'measure' => 'PESO ESTIMADO', 'quantity' => '12500.0000'],
                ],
            ],
        ];

        $page = Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertActionVisible('editGeneralCargoDraft')
            ->mountAction('editGeneralCargoDraft')
            ->assertActionMounted('editGeneralCargoDraft');
        $measurementKey = array_key_first($page->get('mountedActionsData.0.cargo.measurements'));
        $data['cargo']['measurements'] = [$measurementKey => [
            'unit' => '01',
            'measure' => 'PESO ESTIMADO',
            'quantity' => '12500.0000',
        ]];

        $page->setActionData($data)
            ->assertActionDataSet($data)
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Rascunho atualizado.');

        $this->assertSame('5352', $document->refresh()->snapshot['cfop']);
        $this->assertSame('26000.00', $document->snapshot['cargo']['value']);
        $this->assertSame('VEICULOS REVISADOS', $document->snapshot['cargo']['predominant_product']);
    }

    public function test_avulso_draft_edit_action_is_hidden_after_queueing(): void
    {
        City::factory()->create(['ibge_code' => '3504008', 'name' => 'Assis', 'state' => 'SP']);
        City::factory()->create(['ibge_code' => '3523107', 'name' => 'Itaquaquecetuba', 'state' => 'SP']);
        $document = app(\App\Services\Cte\CreateGeneralCargoCteDocument::class)
            ->handle($this->generalCargoFormData());
        $document->forceFill(['status' => CteDocumentStatusEnum::QUEUED])->save();

        Livewire::test(ViewCteEmission::class, ['record' => $document->getRouteKey()])
            ->assertActionHidden('editGeneralCargoDraft');
    }

    public function test_download_actions_appear_when_file_paths_are_present(): void
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

    public function test_listing_actions_are_shown_for_non_empty_paths_without_checking_storage(): void
    {
        $documents = CteDocument::factory()->count(2)->authorized()->create([
            'xml_path' => 'C:\\old-host\\fiscal\\cte-proc.xml',
            'pdf_path' => '/var/lib/legacy/dacte.pdf',
        ]);

        $store = \Mockery::mock(DfeDocumentStore::class);
        $store->shouldNotReceive('exists');
        $this->app->instance(DfeDocumentStore::class, $store);

        Livewire::test(ListCteEmissions::class)
            ->assertCanSeeTableRecords($documents)
            ->assertTableActionVisible('downloadXml', $documents[0])
            ->assertTableActionVisible('downloadPdf', $documents[0])
            ->assertTableActionVisible('regenerateDacte', $documents[0]);
    }

    public function test_listing_hides_download_actions_when_paths_are_empty(): void
    {
        $document = CteDocument::factory()->authorized()->create([
            'xml_path' => null,
            'pdf_path' => null,
        ]);

        Livewire::test(ListCteEmissions::class)
            ->assertTableActionHidden('downloadXml', $document)
            ->assertTableActionHidden('downloadPdf', $document)
            ->assertTableActionHidden('regenerateDacte', $document);
    }

    public function test_missing_file_download_click_still_notifies_user(): void
    {
        $document = CteDocument::factory()->authorized()->create([
            'xml_path' => 'C:\\old-host\\fiscal\\cte-proc.xml',
            'pdf_path' => null,
        ]);

        Livewire::test(ListCteEmissions::class)
            ->callTableAction('downloadXml', $document)
            ->assertNotified('Arquivo fiscal indisponível');
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

    public function test_destination_is_read_from_the_emission_snapshot(): void
    {
        CteDocument::factory()->authorized()->create([
            'snapshot' => [
                'destination' => ['ibge' => '3550308', 'name' => 'Destino fiscal snapshot', 'uf' => 'SP'],
                'modal' => '01',
            ],
        ]);

        Livewire::test(ListCteEmissions::class)
            ->assertSee('Destino fiscal snapshot')
            ->assertDontSee('Rodoviário');
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

    /** @return array<string, mixed> */
    private function generalCargoFormData(): array
    {
        $remitter = $this->company('11111111111111', 'Remetente Industrial', '3504008', 'Assis');
        $recipient = $this->company('22222222222222', 'Destinatário Comercial', '3523107', 'Itaquaquecetuba');

        return [
            'origin_document_mode' => 'nfe',
            'nfe_keys' => '35261011111111111111550010000000011000000011',
            'nfe_xmls' => [],
            'cfop' => '5353',
            'nat_op' => 'PREST SERV TRANSPORTE',
            'origin' => ['ibge' => '3504008', 'name' => 'Assis', 'uf' => 'SP'],
            'destination' => ['ibge' => '3523107', 'name' => 'Itaquaquecetuba', 'uf' => 'SP'],
            'rem_company_id' => (string) $remitter->id,
            'dest_company_id' => (string) $recipient->id,
            'taker_code' => '3',
            'ind_ietoma' => '1',
            'taker_is_transport_company' => '0',
            'cargo' => [
                'value' => '25000.00',
                'predominant_product' => 'MAQUINAS AGRICOLAS',
                'other_category' => '',
                'measurements' => ['peso' => ['unit' => '01', 'measure' => 'PESO ESTIMADO', 'quantity' => '12000.0000']],
            ],
            'freight' => [
                'total' => '5000.00',
                'receivable' => '5000.00',
                'components' => ['frete' => ['name' => 'FRETE', 'value' => '5000.00']],
            ],
        ];
    }

    private function company(string $cnpj, string $name, string $ibge = '3506003', string $city = 'Campinas'): Company
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
