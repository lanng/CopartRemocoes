<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CiotPayerResource\Pages\CreateCiotPayer;
use App\Filament\Resources\CiotPayerResource\Pages\ListCiotPayers;
use App\Filament\Resources\CiotVehicleResource\Pages\CreateCiotVehicle;
use App\Models\CiotPayer;
use App\Models\CiotVehicle;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CiotSupportResourcesTest extends TestCase
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

    public function test_creates_a_payer(): void
    {
        Livewire::test(CreateCiotPayer::class)
            ->fillForm([
                'name' => 'Copart Caçapava',
                'cnpj' => '14517191000925',
                'city' => 'Caçapava',
                'state' => 'SP',
                'zipcode' => '12286140',
                'ibge_code' => '3508504',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(CiotPayer::class, [
            'name' => 'Copart Caçapava',
            'cnpj' => '14517191000925',
        ]);
    }

    public function test_rejects_a_duplicate_payer_cnpj(): void
    {
        CiotPayer::factory()->create(['cnpj' => '14517191000925']);

        Livewire::test(CreateCiotPayer::class)
            ->fillForm([
                'name' => 'Duplicado',
                'cnpj' => '14517191000925',
                'city' => 'Caçapava',
                'state' => 'SP',
            ])
            ->call('create')
            ->assertHasFormErrors(['cnpj']);
    }

    public function test_lists_payers(): void
    {
        $payer = CiotPayer::factory()->create(['name' => 'Copart Osasco']);

        Livewire::test(ListCiotPayers::class)
            ->assertCanSeeTableRecords([$payer]);
    }

    public function test_creates_a_fleet_vehicle(): void
    {
        Livewire::test(CreateCiotVehicle::class)
            ->fillForm([
                'plate' => 'PUC8E55',
                'rntrc' => '045963122',
                'axles' => 3,
                'type' => 'automotor',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(CiotVehicle::class, [
            'plate' => 'PUC8E55',
            'axles' => 3,
        ]);
    }

    public function test_rejects_a_duplicate_plate(): void
    {
        CiotVehicle::factory()->create(['plate' => 'PUC8E55']);

        Livewire::test(CreateCiotVehicle::class)
            ->fillForm([
                'plate' => 'PUC8E55',
                'axles' => 3,
                'type' => 'automotor',
            ])
            ->call('create')
            ->assertHasFormErrors(['plate']);
    }
}
