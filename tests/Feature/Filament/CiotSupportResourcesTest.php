<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CiotVehicleResource\Pages\CreateCiotVehicle;
use App\Filament\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\CiotVehicle;
use App\Models\City;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_requires_address_on_creation(): void
    {
        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'Incompleta',
                'cnpj' => '14517191000925',
                'city' => 'Caçapava',
                'state' => 'SP',
            ])
            ->call('create')
            ->assertHasFormErrors(['street', 'number', 'district', 'zipcode', 'ibge_code']);
    }

    #[DataProvider('companyInscriptionExamples')]
    public function test_accepts_formatted_identifiers_and_optional_ie(?string $ie, ?string $expectedIe): void
    {
        Livewire::test(CreateCompany::class)
            ->fillForm($this->companyFormData(['cnpj' => '05.257.723/0001-30', 'ie' => $ie]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Company::class, [
            'cnpj' => '05257723000130',
            'ie' => $expectedIe,
        ]);
    }

    public static function companyInscriptionExamples(): array
    {
        return [
            'numeric IE with punctuation and leading zero' => ['012.123.123.132', '012123123132'],
            'exempt contributor' => [' isento ', 'ISENTO'],
            'non contributor' => [null, null],
            'empty IE' => ['', null],
        ];
    }

    public function test_rejects_a_duplicate_cnpj_with_punctuation(): void
    {
        Company::factory()->create(['cnpj' => '05257723000130']);

        Livewire::test(CreateCompany::class)
            ->fillForm($this->companyFormData(['cnpj' => '05.257.723/0001-30']))
            ->call('create')
            ->assertHasFormErrors(['cnpj']);
    }

    #[DataProvider('companyInscriptionExamples')]
    public function test_edits_a_company_with_formatted_cnpj_without_conflicting_with_itself(?string $ie, ?string $expectedIe): void
    {
        $company = Company::factory()->create(['cnpj' => '05257723000130']);

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm(['cnpj' => '05.257.723/0001-30', 'ie' => $ie])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('05257723000130', $company->fresh()->cnpj);
        $this->assertSame($expectedIe, $company->fresh()->ie);
        $this->assertDatabaseCount('companies', 1);
    }

    #[DataProvider('invalidCompanyIdentifiers')]
    public function test_rejects_invalid_identifiers_without_removing_unexpected_letters(string $field, string $value): void
    {
        Livewire::test(CreateCompany::class)
            ->fillForm($this->companyFormData([$field => $value]))
            ->call('create')
            ->assertHasFormErrors([$field]);

        $this->assertDatabaseCount('companies', 0);
    }

    public static function invalidCompanyIdentifiers(): array
    {
        return [
            'CNPJ with unexpected letter' => ['cnpj', '05A.257.723/0001-30'],
            'short CNPJ' => ['cnpj', '05.257.723/0001-3'],
            'IE with unexpected letter' => ['ie', '123A.123.123.132'],
            'old absent IE marker' => ['ie', '0'],
            'old exempt IE marker' => ['ie', '1'],
        ];
    }

    public function test_company_model_preserves_leading_zeroes_when_saving_formatted_identifiers(): void
    {
        $company = Company::factory()->create([
            'cnpj' => '05.257.723/0001-30',
            'ie' => '012.123.123.132',
        ])->fresh();

        $this->assertSame('05257723000130', $company->cnpj);
        $this->assertSame('012123123132', $company->ie);
    }

    private function companyFormData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Empresa de teste',
            'cnpj' => '05257723000130',
            'ie' => '123123123132',
            'street' => 'Rua de teste',
            'number' => '10',
            'district' => 'Centro',
            'city' => 'Caçapava',
            'state' => 'SP',
            'zipcode' => '12286140',
            'ibge_code' => '3508504',
        ], $overrides);
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
                'tare_kg' => 8000,
                'type_rod' => '03',
                'body_type' => '00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(CiotVehicle::class, [
            'plate' => 'PUC8E55',
            'axles' => 3,
        ]);
    }

    public function test_creates_a_fleet_vehicle_with_mdfe_technical_data(): void
    {
        Livewire::test(CreateCiotVehicle::class)
            ->fillForm([
                'plate' => 'PUC8E56',
                'rntrc' => '045963122',
                'axles' => 3,
                'type' => 'automotor',
                'renavam' => '12345678901',
                'tare_kg' => 8000,
                'capacity_kg' => 12000,
                'type_rod' => '03',
                'body_type' => '00',
                'uf' => 'SP',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(CiotVehicle::class, [
            'plate' => 'PUC8E56',
            'renavam' => '12345678901',
            'tare_kg' => 8000,
            'capacity_kg' => 12000,
            'type_rod' => '03',
            'body_type' => '00',
            'uf' => 'SP',
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

    public function test_the_company_form_autocompletes_from_the_zipcode(): void
    {
        City::query()->create(['ibge_code' => '3523107', 'name' => 'Itaquaquecetuba', 'state' => 'SP']);

        Http::fake([
            'https://brasilapi.com.br/api/cep/v2/08597230' => Http::response([
                'cep' => '08597230',
                'state' => 'SP',
                'city' => 'Itaquaquecetuba',
                'street' => 'Estrada Muranaka',
                'neighborhood' => 'Jd. Novo Horizonte',
            ], 200),
        ]);

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'Copart Itaquaquecetuba',
                'cnpj' => '14517191000178',
                'ie' => '379121178113',
                'number' => '199',
                'zipcode' => '08597-230',
            ])
            ->callFormComponentAction('zipcode', 'buscarCep')
            ->assertFormSet([
                'city' => 'Itaquaquecetuba',
                'state' => 'SP',
                'street' => 'Estrada Muranaka',
                'district' => 'Jd. Novo Horizonte',
                'ibge_code' => '3523107',
                'number' => '199',
            ]);
    }
}
