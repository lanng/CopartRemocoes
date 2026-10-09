<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Company;
use Database\Seeders\CompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompanySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_restores_the_complete_company_catalog_without_external_lookups(): void
    {
        Http::preventStrayRequests();
        $this->seed(CompanySeeder::class);

        $this->assertDatabaseCount('companies', 29);
        $this->assertDatabaseHas('companies', ['cnpj' => '03215790000110', 'ie' => null]);
        $this->assertDatabaseHas('companies', ['cnpj' => '90180605000102', 'ie' => 'ISENTO']);
        foreach (Company::all() as $company) {
            $this->assertMatchesRegularExpression('/^\d{14}$/', $company->cnpj);
            $this->assertMatchesRegularExpression('/^\d{7}$/', $company->ibge_code);
            $this->assertNotEmpty($company->street);
            $this->assertNotContains($company->ie, ['0', '1']);
        }
        Http::assertNothingSent();
    }

    public function test_repeated_seeding_skips_existing_cnpjs_without_modifying_any_field(): void
    {
        City::factory()->create(['ibge_code' => '3508504', 'name' => 'Caçapava', 'state' => 'SP']);
        $existing = Company::factory()->create([
            'cnpj' => '14517191000925',
            'name' => 'Razão social ajustada no servidor',
            'ie' => null,
            'ibge_code' => null,
            'is_active' => false,
        ])->fresh();
        $original = $existing->getAttributes();

        $this->seed(CompanySeeder::class);
        $this->seed(CompanySeeder::class);

        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('companies', 29);
    }
}
