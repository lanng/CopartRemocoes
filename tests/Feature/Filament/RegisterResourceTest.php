<?php

namespace Tests\Feature\Filament;

use App\Enums\CteDocumentStatusEnum;
use App\Filament\Resources\RegisterResource\Pages\ListRegisters;
use App\Filament\Resources\RegisterResource\Pages\ViewRegister;
use App\Models\CteDocument;
use App\Models\Register;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RegisterResourceTest extends TestCase
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

    public function test_registers_can_be_searched_by_historical_cte_number(): void
    {
        $linkedRegister = Register::factory()->create(['vehicle_model' => 'Linked vehicle']);
        $unlinkedRegister = Register::factory()->create(['vehicle_model' => 'Unlinked vehicle']);

        CteDocument::factory()->create([
            'register_id' => $linkedRegister->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '900100',
        ]);
        CteDocument::factory()->create([
            'register_id' => $linkedRegister->id,
            'status' => CteDocumentStatusEnum::SUPERSEDED,
            'cte_number' => '800001',
        ]);
        CteDocument::factory()->create([
            'register_id' => $unlinkedRegister->id,
            'status' => CteDocumentStatusEnum::QUEUED,
            'cte_number' => '900200',
        ]);

        Livewire::test(ListRegisters::class)
            ->searchTable('800001')
            ->assertCanSeeTableRecords([$linkedRegister])
            ->assertCanNotSeeTableRecords([$unlinkedRegister]);
    }

    public function test_register_view_shows_the_latest_authorized_cte_and_delivery_date(): void
    {
        $register = Register::factory()->create([
            'delivery_confirmed_at' => Carbon::create(2026, 8, 12, 15, 30, 'UTC'),
        ]);

        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '100001',
            'issued_at' => Carbon::create(2026, 8, 10, 12, 0, 'UTC'),
            'authorized_at' => Carbon::create(2026, 8, 10, 13, 0, 'UTC'),
        ]);
        $latest = CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '100002',
            'issued_at' => Carbon::create(2026, 8, 11, 12, 0, 'UTC'),
            'authorized_at' => Carbon::create(2026, 8, 11, 13, 0, 'UTC'),
            'protocol' => 'protocol-secret',
            'access_key' => '35260812563112000130570010000026701338262343',
        ]);

        Livewire::test(ViewRegister::class, ['record' => $register->getRouteKey()])
            ->assertFormFieldExists('vehicle_model')
            ->assertFormFieldIsDisabled('vehicle_model')
            ->assertFormFieldExists('vehicle_plate')
            ->assertFormFieldIsDisabled('vehicle_plate')
            ->assertFormFieldExists('origin_city')
            ->assertFormFieldIsDisabled('origin_city')
            ->assertFormFieldExists('status')
            ->assertFormFieldIsDisabled('status')
            ->assertFormFieldExists('value')
            ->assertFormFieldIsDisabled('value')
            ->assertSee('Número do CT-e')
            ->assertSee('100002')
            ->assertSee('Emissão do CT-e')
            ->assertSee('11/08/2026 09:00')
            ->assertSee('Data da entrega')
            ->assertSee('12/08/2026 12:30')
            ->assertDontSee($latest->protocol)
            ->assertDontSee($latest->access_key);
    }

    public function test_register_view_shows_placeholders_when_cte_and_delivery_are_missing(): void
    {
        $register = Register::factory()->create();

        Livewire::test(ViewRegister::class, ['record' => $register->getRouteKey()])
            ->assertSee('Número do CT-e')
            ->assertSee('Emissão do CT-e')
            ->assertSee('Data da entrega')
            ->assertSee('Não informado');
    }
}
