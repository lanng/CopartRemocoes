<?php

namespace Tests\Feature\Filament;

use App\Enums\PaymentBatchStatusEnum;
use App\Filament\Resources\PaymentBatchResource;
use App\Filament\Resources\PaymentBatchResource\Pages\ListPaymentBatches;
use App\Filament\Resources\PaymentBatchResource\Pages\ViewPaymentBatch;
use App\Filament\Resources\PaymentBatchResource\RelationManagers\ItemsRelationManager;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\Register;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentBatchResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    public function test_the_payment_batch_resource_uses_financial_labels_and_shows_snapshots(): void
    {
        $batch = PaymentBatch::factory()->create([
            'status' => PaymentBatchStatusEnum::PENDING,
            'window_start' => '2026-08-04',
            'window_end' => '2026-08-10',
            'total_amount' => '123.45',
        ]);
        $register = Register::factory()->create(['vehicle_plate' => 'ABC1D23']);
        PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'register_id' => $register->id,
            'vehicle_plate' => 'ABC1D23',
            'amount' => '123.45',
            'cte_number' => '9001',
            'delivery_confirmed_at' => Carbon::create(2026, 8, 8, 15, 30, 'UTC'),
        ]);

        $this->assertSame('Lotes de pagamento', PaymentBatchResource::getNavigationLabel());
        $this->assertSame('Financeiro', PaymentBatchResource::getNavigationGroup());

        Livewire::test(ListPaymentBatches::class)
            ->assertCanSeeTableRecords([$batch])
            ->assertTableActionHasLabel('confirm', 'Confirmar pagamento', $batch)
            ->assertTableColumnExists('window_start', fn ($column): bool => $column->getLabel() === 'Início')
            ->assertTableColumnExists('total_amount', fn ($column): bool => $column->getLabel() === 'Total');

        Livewire::test(ViewPaymentBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSee('Início')
            ->assertSee('04/08/2026')
            ->assertSee('Total')
            ->assertSee('123.45')
            ->assertSee('ABC1D23')
            ->assertSee('9001')
            ->assertSee('08/08/2026 12:30');
    }

    public function test_the_register_action_works_for_payment_batch_items(): void
    {
        $batch = PaymentBatch::factory()->create();
        $register = Register::factory()->create([
            'vehicle_model' => 'Sprinter 416',
            'vehicle_plate' => 'ABC1D23',
            'vehicle_id' => 'VEH-987',
        ]);
        $item = PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'register_id' => $register->id,
        ]);

        $component = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewPaymentBatch::class,
        ])
            ->assertTableActionHasLabel('viewRegister', 'Ver registro', $item)
            ->mountTableAction('viewRegister', $item)
            ->assertTableActionMounted('viewRegister')
            ->assertSee('Identificação')
            ->assertSee('Rota e prazos')
            ->assertSee('Operação')
            ->assertSee('Financeiro')
            ->assertSee('Sprinter 416')
            ->assertSee('ABC1D23')
            ->assertSee('VEH-987')
            ->assertSee('43.897,00')
            ->assertDontSee('PDF');

        $this->assertArrayHasKey('register', $component->instance()->getTable()->getQuery()->getEagerLoads());
    }

    public function test_a_pending_item_can_be_removed_from_the_payment_batch(): void
    {
        $batch = PaymentBatch::factory()->create([
            'status' => PaymentBatchStatusEnum::PENDING,
            'total_amount' => '750.00',
        ]);
        $register = Register::factory()->create(['status' => 'delivered']);
        $item = PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'register_id' => $register->id,
            'amount' => '750.00',
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewPaymentBatch::class,
        ])
            ->assertTableActionHasLabel('remove', 'Retirar do lote', $item)
            ->callTableAction('remove', $item);

        $this->assertModelMissing($item);
        $this->assertModelMissing($batch);
        $this->assertNotNull($register->refresh()->payment_deferred_at);
    }

    public function test_confirmed_payment_items_cannot_be_removed_from_the_interface(): void
    {
        $batch = PaymentBatch::factory()->create(['status' => PaymentBatchStatusEnum::CONFIRMED]);
        $item = PaymentBatchItem::factory()->create(['payment_batch_id' => $batch->id]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewPaymentBatch::class,
        ])->assertTableActionHidden('remove', $item);
    }
}
