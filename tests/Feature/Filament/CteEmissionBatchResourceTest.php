<?php

namespace Tests\Feature\Filament;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Filament\Resources\CteEmissionBatchResource\Pages\ListCteEmissionBatches;
use App\Filament\Resources\CteEmissionBatchResource\Pages\ViewCteEmissionBatch;
use App\Filament\Resources\CteEmissionBatchResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\RegisterResource;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CteEmissionBatchResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    public function test_the_batch_list_uses_translated_labels_and_brasilia_dates(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            'execution_mode' => 'dry_run',
            'approved_at' => Carbon::create(2026, 8, 12, 15, 30, 'UTC'),
            'created_at' => Carbon::create(2026, 8, 12, 16, 30, 'UTC'),
        ]);

        $component = Livewire::test(ListCteEmissionBatches::class)
            ->assertCanSeeTableRecords([$batch])
            ->assertTableColumnFormattedStateSet('status', 'Concluído com erros', $batch)
            ->assertTableColumnFormattedStateSet('execution_mode', 'Simulação', $batch)
            ->assertTableColumnFormattedStateSet('approved_at', '12/08/2026 12:30', $batch)
            ->assertTableColumnFormattedStateSet('created_at', '12/08/2026 13:30', $batch)
            ->assertTableColumnExists('status', fn ($column): bool => $column->getLabel() === 'Situação')
            ->assertTableColumnExists('execution_mode', fn ($column): bool => $column->getLabel() === 'Modo')
            ->assertTableColumnExists('approved_at', fn ($column): bool => $column->getLabel() === 'Aprovado em')
            ->assertTableColumnExists('created_at', fn ($column): bool => $column->getLabel() === 'Criado em')
            ->assertTableColumnExists('documents_count', fn ($column): bool => $column->getLabel() === 'Itens')
            ->assertTableActionHasLabel('view', 'Ver lote', $batch)
            ->assertTableHeaderActionsExistInOrder([]);

        $this->assertSame(
            CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS->label(),
            $component->instance()->getTable()->getFilter('status')->getOptions()[CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS->value],
        );
        $this->assertSame('Situação', $component->instance()->getTable()->getFilter('status')->getLabel());
        $this->assertSame('Modo', $component->instance()->getTable()->getFilter('execution_mode')->getLabel());
        $this->assertSame([
            'dry_run' => 'Simulação',
            'live' => 'Emissão real',
        ], $component->instance()->getTable()->getFilter('execution_mode')->getOptions());
    }

    public function test_the_batch_detail_uses_translated_labels_and_actions(): void
    {
        $creator = User::factory()->create(['name' => 'Criador do lote']);
        $approver = User::factory()->create(['name' => 'Aprovador do lote']);
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
            'execution_mode' => 'live',
            'created_by' => $creator->id,
            'approved_by' => $approver->id,
            'approved_at' => Carbon::create(2026, 8, 12, 15, 30, 'UTC'),
            'processing_started_at' => Carbon::create(2026, 8, 12, 16, 30, 'UTC'),
            'completed_at' => Carbon::create(2026, 8, 12, 17, 30, 'UTC'),
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'fipe_value' => '43897.00',
                'value' => '1250.00',
            ],
        ]);
        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSee('Valor total da carga')
            ->assertSee('43.897,00')
            ->assertSee('Valor total do transporte')
            ->assertSee('1.250,00')
            ->assertSee('Situação')
            ->assertSee('Rascunho')
            ->assertSee('Modo')
            ->assertSee('Emissão real')
            ->assertSee('Criado por')
            ->assertSee('Criador do lote')
            ->assertSee('Aprovado por')
            ->assertSee('Aprovador do lote')
            ->assertSee('Aprovado em')
            ->assertSee('12/08/2026 12:30')
            ->assertSee('Processamento iniciado em')
            ->assertSee('12/08/2026 13:30')
            ->assertSee('Concluído em')
            ->assertSee('12/08/2026 14:30')
            ->assertActionHasLabel('delete', 'Excluir lote')
            ->assertActionHasLabel('approve', 'Aprovar lote');
    }

    public function test_the_delete_action_is_only_available_for_draft_batches(): void
    {
        $draft = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $approved = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);

        Livewire::test(ListCteEmissionBatches::class)
            ->assertTableActionHasLabel('delete', 'Excluir lote', $draft)
            ->assertTableActionHidden('delete', $approved);
    }

    public function test_a_draft_batch_can_be_deleted_from_the_batch_list(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
        ]);

        Livewire::test(ListCteEmissionBatches::class)
            ->callTableAction('delete', $batch);

        $this->assertModelMissing($batch);
        $this->assertModelMissing($document);
    }

    public function test_a_draft_batch_can_be_deleted_from_the_batch_detail(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()])
            ->callAction('delete')
            ->assertRedirect(CteEmissionBatchResource::getUrl('index'));

        $this->assertModelMissing($batch);
        $this->assertModelMissing($document);
    }

    public function test_documents_show_translated_state_and_the_register_action(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ]);
        $reconciliationDocument = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        $component = Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertCanSeeTableRecords([$document])
            ->assertTableColumnFormattedStateSet('status', 'Falha antes da autorização', $document)
            ->assertTableColumnExists('cte_number', fn ($column): bool => $column->getLabel() === 'Número do CT-e')
            ->assertTableColumnExists('authorized_at', fn ($column): bool => $column->getLabel() === 'Autorizado em')
            ->assertTableActionHasLabel('retry', 'Tentar novamente', $document)
            ->assertTableActionHasLabel('reconcile', 'Conciliar', $reconciliationDocument)
            ->assertTableActionHasLabel('viewRegister', 'Ver registro', $document);

        $this->assertArrayHasKey('register', $component->instance()->getTable()->getQuery()->getEagerLoads());
    }

    public function test_a_draft_document_can_be_removed_from_a_draft_batch(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableActionHasLabel('remove', 'Retirar do lote', $document)
            ->callTableAction('remove', $document);

        $this->assertModelMissing($document);
        $this->assertModelMissing($batch);
        $this->assertModelExists($document->register);
    }

    public function test_failed_documents_can_be_retried_in_bulk_and_reopen_the_batch(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        $failed = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ]);
        $authorized = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableBulkActionHasLabel('retrySelected', 'Tentar novamente selecionados')
            ->callTableBulkAction('retrySelected', [$failed, $authorized]);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $failed->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->refresh()->status);
        $this->assertSame(CteEmissionBatchStatusEnum::PROCESSING, $batch->refresh()->status);
    }

    public function test_the_register_action_links_to_the_complete_register(): void
    {
        $this->app->setLocale('pt_BR');

        $batch = CteEmissionBatch::factory()->create();
        $register = Register::factory()->create([
            'vehicle_model' => 'Sprinter 416',
            'vehicle_plate' => 'ABC1D23',
            'vehicle_id' => 'VEH-987',
            'company' => 'millan',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Campinas',
            'deadline_withdraw' => '2026-08-10',
            'deadline_delivery' => '2026-08-12',
            'collected_date' => '2026-08-11',
            'delivery_confirmed_at' => Carbon::create(2026, 8, 12, 18, 45, 'UTC'),
            'driver' => 'João da Silva',
            'driver_plate' => 'XYZ9K87',
            'tow_yard' => 'Pátio Central',
            'status' => RegisterStatusEnum::COLLECTED,
            'payment_code' => 'PAY-123',
            'insurance' => 'Allianz Seguros',
            'fipe_value' => '43897.00',
            'value' => '1250.00',
            'pdf_path' => 'registers/abc1d23.pdf',
            'notes' => 'Carga liberada para transporte.',
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
        ]);

        $component = Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableActionHasLabel('viewRegister', 'Ver registro', $document)
            ->mountTableAction('viewRegister', $document)
            ->assertTableActionMounted('viewRegister')
            ->assertSee('Identificação')
            ->assertSee('Rota e prazos')
            ->assertSee('Operação')
            ->assertSee('Financeiro')
            ->assertSee('Veículo')
            ->assertSee('Sprinter 416')
            ->assertSee('Placa')
            ->assertSee('ABC1D23')
            ->assertSee('Código do veículo')
            ->assertSee('VEH-987')
            ->assertSee('Empresa')
            ->assertSee('millan')
            ->assertSee('Origem')
            ->assertSee('São Paulo')
            ->assertSee('Destino')
            ->assertSee('Campinas')
            ->assertSee('Retirada até')
            ->assertSee('10/08/2026')
            ->assertSee('Entrega até')
            ->assertSee('12/08/2026')
            ->assertSee('Data da recolha')
            ->assertSee('11/08/2026')
            ->assertSee('Entrega confirmada')
            ->assertSee('12/08/2026 15:45')
            ->assertSee('Motorista')
            ->assertSee('João da Silva')
            ->assertSee('Placa guincho')
            ->assertSee('XYZ9K87')
            ->assertSee('Pátio')
            ->assertSee('Pátio Central')
            ->assertSee('Situação')
            ->assertSee('Coletado')
            ->assertSee('Código pagamento')
            ->assertSee('PAY-123')
            ->assertSee('Seguradora')
            ->assertSee('Allianz Seguros')
            ->assertSee('Valor FIPE')
            ->assertSee('R$')
            ->assertSee('43.897,00')
            ->assertSee('Valor do serviço')
            ->assertSee('1.250,00')
            ->assertDontSee('PDF')
            ->assertDontSee('registers/abc1d23.pdf')
            ->assertSee('Observações')
            ->assertSee('Carga liberada para transporte.')
            ->assertSee('Abrir registro completo');

        $action = $component->instance()->getMountedTableAction();

        $this->assertNull($action->getModalSubmitAction());
        $this->assertSame(MaxWidth::FiveExtraLarge, $action->getModalWidth());
        $this->assertTrue($action->isModalHeaderSticky());
        $this->assertTrue($action->isModalFooterSticky());
        $this->assertSame(
            RegisterResource::getUrl('view', ['record' => $register]),
            $action->getModalAction('openRegister')->getUrl(),
        );
    }

    public function test_the_register_action_shows_placeholders_for_missing_optional_data(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $register = Register::factory()->create([
            'collected_date' => null,
            'delivery_confirmed_at' => null,
            'driver' => null,
            'driver_plate' => null,
            'tow_yard' => null,
            'payment_code' => null,
            'insurance' => null,
            'fipe_value' => null,
            'pdf_path' => null,
            'notes' => null,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->mountTableAction('viewRegister', $document)
            ->assertSee('Não informado');
    }
}
