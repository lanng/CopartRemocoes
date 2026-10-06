<?php

namespace Tests\Feature\Filament;

use App\Enums\CteDocumentStatusEnum;
use App\Filament\Resources\MdfeDocumentResource;
use App\Filament\Resources\MdfeDocumentResource\Pages\ManageMdfeDocuments;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Models\MdfeEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MdfeDocumentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.env' => 'local']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    public function test_lists_authorized_direct_manifests_that_are_not_confirmed_closed(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $open = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('1', 44),
            'protocol' => '935260000000001',
            'fiscal_status_code' => '100',
        ]);
        $closed = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('2', 44),
            'protocol' => '935260000000002',
            'fiscal_status_code' => '132',
        ]);
        $notAuthorized = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::QUEUED,
            'emitter' => 'direct',
            'access_key' => null,
        ]);

        Livewire::test(ManageMdfeDocuments::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$closed, $notAuthorized]);

        $this->get(MdfeDocumentResource::getUrl('index'))->assertSuccessful();

    }

    public function test_open_manifest_page_offers_close_retry_and_damdfe_actions_without_mutating_fiscal_documents(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $document = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('3', 44),
            'protocol' => '935260000000003',
            'fiscal_status_code' => '100',
            'xml_path' => 'mdfe/'.str_repeat('3', 44).'/mdfe-proc.xml',
        ]);

        Livewire::test(ManageMdfeDocuments::class)
            ->assertTableActionVisible('closeMdfe', $document)
            ->assertTableActionVisible('downloadDamdfe', $document)
            ->assertTableActionVisible('viewBatch', $document)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');

        MdfeEvent::factory()->create([
            'mdfe_document_id' => $document->id,
            'status' => 'registered',
            'event_id' => 'ID110112'.str_repeat('3', 44).'01',
        ]);

        Livewire::test(ManageMdfeDocuments::class)
            ->assertTableActionHidden('closeMdfe', $document);
    }
}
