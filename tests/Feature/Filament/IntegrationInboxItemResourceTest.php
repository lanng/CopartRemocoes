<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\IntegrationInboxItemResource;
use App\Filament\Resources\IntegrationInboxItemResource\Pages\ListIntegrationInboxItems;
use App\Models\IntegrationInboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrationInboxItemResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_the_translated_financial_inbox(): void
    {
        $pending = IntegrationInboxItem::factory()->create([
            'status' => 'pending',
            'failure_reason' => 'vehicle_plate_mismatch',
            'received_at' => Carbon::create(2026, 8, 12, 15, 30, 'UTC'),
        ]);
        $processed = IntegrationInboxItem::factory()->create(['status' => 'processed']);

        $this->assertSame('Baixas por e-mail', IntegrationInboxItemResource::getNavigationLabel());
        $this->assertSame('Financeiro', IntegrationInboxItemResource::getNavigationGroup());

        Livewire::test(ListIntegrationInboxItems::class)
            ->assertSee('Pendente')
            ->assertSee('Placa divergente')
            ->assertSee('12/08/2026 12:30')
            ->assertSee('Conciliar')
            ->assertDontSee('ignored')
            ->assertDontSee($processed->external_id);

        $this->assertSame('Processado', $processed->refresh()->statusLabel());
        $this->assertSame('Placa divergente', $pending->failureReasonLabel());
    }
}
