<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CiotVehicleResource\Pages\CreateCiotVehicle;
use App\Filament\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\CiotVehicle;
use App\Models\Company;
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

    public function test_creates_a_company_with_the_fiscal_fields_required_for_cte(): void
    {
        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'Copart Caçapava',
                'cnpj' => '14517191000925',
                'ie' => '319211381113',
                'street' => 'Av Henry Nestle',
                'number' => '1500',
                'district' => 'Vila Galvao',
                'city' => 'Caçapava',
                'state' => 'SP',
                'zipcode' => '12286140',
                'ibge_code' => '3508504',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Company::class, [
            'name' => 'Copart Caçapava',
            'cnpj' => '14517191000925',
            'ie' => '319211381113',
        ]);
    }

    public function test_requires_ie_and_address_on_creation(): void
    {
        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'Incompleta',
                'cnpj' => '14517191000925',
                'city' => 'Caçapava',
                'state' => 'SP',
            ])
            ->call('create')
            ->assertHasFormErrors(['ie', 'street', 'number', 'district', 'zipcode', 'ibge_code']);
    }

    public function test_rejects_a_duplicate_company_cnpj(): void
    {
        Company::factory()->create(['cnpj' => '14517191000925']);

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'Duplicado',
                'cnpj' => '14517191000925',
                'ie' => '319211381113',
                'street' => 'Av Henry Nestle',
                'number' => '1500',
                'district' => 'Vila Galvao',
                'city' => 'Caçapava',
                'state' => 'SP',
                'zipcode' => '12286140',
                'ibge_code' => '3508504',
            ])
            ->call('create')
            ->assertHasFormErrors(['cnpj']);
    }

    public function test_lists_companies(): void
    {
        $company = Company::factory()->create(['name' => 'Copart Osasco']);

        Livewire::test(ListCompanies::class)
            ->assertCanSeeTableRecords([$company]);
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
