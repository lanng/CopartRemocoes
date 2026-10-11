<?php

namespace Tests\Feature;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Filament\Resources\CteEmissionBatchResource\Pages\ViewCteEmissionBatch;
use App\Filament\Resources\CteEmissionBatchResource\RelationManagers\DocumentsRelationManager;
use App\Jobs\EmitCteDirectJob;
use App\Models\City;
use App\Models\Company;
use App\Models\CompanyFiscalAlias;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use App\Services\Ciot\PrefillCiotFromBatch;
use App\Services\Cte\ApproveCteEmissionBatch;
use App\Services\Cte\BuildCteXml;
use App\Services\Cte\CheckCteBatchParties;
use App\Services\Cte\CreateCteEmissionBatch;
use App\Services\Cte\SaveCteFiscalAlias;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\XmlSigner;
use App\Services\Dfe\XsdValidator;
use Database\Seeders\CompanyFiscalAliasSeeder;
use Database\Seeders\CompanySeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class CteBatchPartyReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cte.emitter' => 'direct']);
        config(['ciot.distance.api_key' => null]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->seed(CompanySeeder::class);
        City::factory()->create(['ibge_code' => '3534401', 'name' => 'Osasco', 'state' => 'SP']);
    }

    public function test_approval_waits_for_all_parties_without_queuing_any_document(): void
    {
        $batch = $this->batch('SEGURADORA DESCONHECIDA');
        try {
            app(ApproveCteEmissionBatch::class)->handle($batch, $this->user);
            $this->fail('A aprovação deve aguardar a conciliação.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('SEGURADORA DESCONHECIDA', $exception->getMessage());
        }
        $this->assertSame(CteEmissionBatchStatusEnum::DRAFT, $batch->fresh()->status);
        $this->assertSame(CteDocumentStatusEnum::DRAFT, $batch->documents->first()->fresh()->status);
        $this->assertNull($batch->fresh()->approved_at);
        Queue::assertNotPushed(EmitCteDirectJob::class);
        $this->assertDatabaseCount('cte_number_sequences', 0);
    }

    public function test_a_learned_sender_is_reused_and_frozen_without_changing_the_register(): void
    {
        $batch = $this->batch('NOVA SEGURADORA');
        $document = $batch->documents->first();
        $company = Company::where('cnpj', '34504257000100')->firstOrFail();
        app(SaveCteFiscalAlias::class)->handle($batch, $document, 'sender', $company->id, null, $this->user);
        app(ApproveCteEmissionBatch::class)->handle($batch, $this->user);

        $snapshot = $document->fresh()->snapshot;
        $this->assertSame(2, $snapshot['schema_version']);
        $this->assertSame('34504257000100', $snapshot['rem']['cnpj']);
        $this->assertSame('NOVA SEGURADORA', $snapshot['insurance']);
        $this->assertSame('NOVA SEGURADORA', $document->register->insurance);
        $company->update(['street' => 'Endereço alterado depois da aprovação']);
        $this->assertSame($snapshot, $document->fresh()->snapshot);

        $future = $this->batch(' nova   seguradora ');
        $this->assertTrue(app(CheckCteBatchParties::class)->isReady($future));
        $this->assertDatabaseCount('company_fiscal_aliases', 1);
    }

    public function test_one_pending_record_blocks_the_entire_batch_without_freezing_or_queuing_other_records(): void
    {
        $registers = collect([
            Register::factory()->create(['origin_city' => 'OSASCO', 'destination_city' => 'OSASCO', 'insurance' => 'HDI SEGUROS SA NOVO']),
            Register::factory()->create(['origin_city' => 'OSASCO', 'destination_city' => 'OSASCO', 'insurance' => 'NOVA SEGURADORA']),
        ]);
        $batch = app(CreateCteEmissionBatch::class)->handle($registers, $this->user, 'live');

        try {
            app(ApproveCteEmissionBatch::class)->handle($batch, $this->user);
            $this->fail('Todos os registros precisam estar conferidos.');
        } catch (ValidationException) {
            foreach ($batch->documents()->get() as $document) {
                $this->assertSame(CteDocumentStatusEnum::DRAFT, $document->status);
                $this->assertSame(1, $document->snapshot['schema_version']);
            }
            Queue::assertNotPushed(EmitCteDirectJob::class);
        }
    }

    public function test_the_same_source_name_has_independent_sender_and_destination_associations(): void
    {
        $this->seed(CompanyFiscalAliasSeeder::class);
        $batch = $this->batch('PORTO SEGURO', 'PORTO SEGURO');
        $company = Company::where('cnpj', '14517191000410')->firstOrFail();
        $city = City::where('ibge_code', '3534401')->firstOrFail();
        app(SaveCteFiscalAlias::class)->handle($batch, $batch->documents->first(), 'destination', $company->id, $city->id, $this->user);
        app(ApproveCteEmissionBatch::class)->handle($batch, $this->user);

        $snapshot = $batch->documents->first()->fresh()->snapshot;
        $this->assertSame('61198164000160', $snapshot['rem']['cnpj']);
        $this->assertSame('14517191000410', $snapshot['dest']['cnpj']);
    }

    public function test_the_initial_aliases_use_cnpjs_and_skip_existing_translations(): void
    {
        $this->seed(CompanyFiscalAliasSeeder::class);
        $this->assertDatabaseCount('company_fiscal_aliases', 30);
        $alias = CompanyFiscalAlias::where('source_name', 'LOOVI TECHNOLOGY')->firstOrFail();
        $this->assertSame('34504257000100', $alias->company->cnpj);
        $alias->update(['company_id' => Company::where('cnpj', '90180605000102')->value('id')]);
        $this->seed(CompanyFiscalAliasSeeder::class);
        $this->assertSame('90180605000102', $alias->fresh()->company->cnpj);
        $this->assertDatabaseCount('company_fiscal_aliases', 30);
    }

    public function test_all_seeded_sender_aliases_produce_schema_valid_signed_xml(): void
    {
        $this->seed(CompanyFiscalAliasSeeder::class);
        $certificate = DfeTestCertificate::create();
        config(['dfe.tp_amb' => '1', 'dfe.numbering_start' => '123']);
        $signer = new XmlSigner(new SefazCertificate($certificate['path'], $certificate['password']));

        foreach (CompanyFiscalAlias::where('role', 'sender')->get() as $alias) {
            $batch = $this->batch($alias->source_name);
            app(ApproveCteEmissionBatch::class)->handle($batch, $this->user);
            $built = app(BuildCteXml::class)->handle($batch->documents->first()->fresh()->snapshot);
            $signed = $signer->sign($built->xml, 'infCte', 'http://www.portalfiscal.inf.br/cte', 'CTe'.$built->accessKey);
            app(XsdValidator::class)->validateCte($signed);
            $this->assertStringContainsString('<CNPJ>'.$alias->company->cnpj.'</CNPJ>', $signed);
        }
    }

    public function test_the_confirmed_patio_alias_selects_the_correct_branch_and_city(): void
    {
        $this->seed(CompanyFiscalAliasSeeder::class);
        $batch = $this->batch('LOOVI TECHNOLOGY', 'LEILAO PATIO PORTO SEGURO');
        app(ApproveCteEmissionBatch::class)->handle($batch, $this->user);
        $snapshot = $batch->documents->first()->fresh()->snapshot;
        $this->assertSame('14517191000410', $snapshot['dest']['cnpj']);
        $this->assertSame('3534401', $snapshot['destination']['ibge']);
        $this->assertSame('CW TECHNOLOGY LTDA', $snapshot['rem']['xNome']);
        $this->assertSame('LEILAO PATIO PORTO SEGURO', $snapshot['destination_city']);
    }

    public function test_missing_company_and_ambiguous_destination_require_conciliation(): void
    {
        Company::factory()->create(['city' => 'Osasco', 'state' => 'SP', 'ibge_code' => '3534401']);
        $batch = $this->batch('HDI SEGUROS SA NOVO');
        $this->assertFalse(app(CheckCteBatchParties::class)->isReady($batch));
        $company = Company::where('cnpj', '14517191000410')->firstOrFail();
        $city = City::where('ibge_code', '3534401')->firstOrFail();
        app(SaveCteFiscalAlias::class)->handle($batch, $batch->documents->first(), 'destination', $company->id, $city->id, $this->user);
        $this->assertTrue(app(CheckCteBatchParties::class)->isReady($batch));
    }

    public function test_inactive_and_incomplete_companies_block_approval(): void
    {
        $this->seed(CompanyFiscalAliasSeeder::class);
        $batch = $this->batch('LOOVI TECHNOLOGY');
        $company = Company::where('cnpj', '34504257000100')->firstOrFail();
        $company->update(['is_active' => false]);
        $this->assertFalse(app(CheckCteBatchParties::class)->isReady($batch));
        $company->update(['is_active' => true, 'street' => null]);
        $this->assertFalse(app(CheckCteBatchParties::class)->isReady($batch));
    }

    public function test_destination_city_can_differ_from_the_fiscal_address(): void
    {
        $batch = $this->batch('HDI SEGUROS SA NOVO', 'ENTREGA ESPECIAL');
        $company = Company::where('cnpj', '14517191000925')->firstOrFail();
        $city = City::where('ibge_code', '3534401')->firstOrFail();
        app(SaveCteFiscalAlias::class)->handle($batch, $batch->documents->first(), 'destination', $company->id, $city->id, $this->user);
        app(ApproveCteEmissionBatch::class)->handle($batch, $this->user);
        $snapshot = $batch->documents->first()->fresh()->snapshot;
        $this->assertSame('Caçapava', $snapshot['dest']['xMun']);
        $this->assertSame('Osasco', $snapshot['destination']['name']);
        $prefill = app(PrefillCiotFromBatch::class)->handle($batch->fresh());
        $this->assertSame($company->id, $prefill['form']['payer_id']);
        $this->assertSame('Osasco', $prefill['form']['destination']['cidade']);
    }

    public function test_alias_cannot_be_changed_through_an_approved_batch(): void
    {
        $batch = $this->batch('HDI SEGUROS SA NOVO');
        app(ApproveCteEmissionBatch::class)->handle($batch, $this->user);
        $this->expectException(ValidationException::class);
        app(SaveCteFiscalAlias::class)->handle($batch, $batch->documents->first(), 'sender', Company::first()->id, null, $this->user);
    }

    public function test_ciot_uses_only_the_reconciled_branch_when_other_companies_share_the_city(): void
    {
        $this->seed(CompanyFiscalAliasSeeder::class);
        Company::factory()->create(['city' => 'Osasco', 'state' => 'SP', 'ibge_code' => '3534401']);
        $batch = $this->batch('HDI SEGUROS SA NOVO');
        app(ApproveCteEmissionBatch::class)->handle($batch, $this->user);

        $prefill = app(PrefillCiotFromBatch::class)->handle($batch->fresh());
        $this->assertSame(['14517191000410'], $prefill['patios']->pluck('cnpj')->all());
    }

    public function test_a_new_destination_company_can_be_created_without_leaving_the_lot(): void
    {
        $city = City::factory()->create(['ibge_code' => '3523107', 'name' => 'Itaquaquecetuba', 'state' => 'SP']);
        $batch = $this->batch('HDI SEGUROS SA NOVO', 'NOVO PATIO');
        $component = Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewCteEmissionBatch::class])
            ->mountTableAction('resolveDestination', $batch->documents->first())
            ->callFormComponentAction('company_id', 'createOption', [
                'name' => 'Nova filial de teste', 'cnpj' => '12.345.678/0001-99', 'ie' => '123.456.789',
                'street' => 'Rua de teste', 'number' => '10', 'district' => 'Centro',
                'city' => 'Itaquaquecetuba', 'state' => 'SP', 'zipcode' => '08597230', 'ibge_code' => '3523107',
            ], formName: 'mountedTableActionForm')
            ->assertHasNoFormComponentActionErrors();
        $company = Company::where('cnpj', '12345678000199')->firstOrFail();
        $component->setTableActionData(['company_id' => $company->id, 'city_id' => $city->id])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertTrue(app(CheckCteBatchParties::class)->isReady($batch));
    }

    public function test_draft_table_keeps_the_plate_and_exposes_conciliation_instead_of_fiscal_columns(): void
    {
        $batch = $this->batch('NOVA SEGURADORA');
        $document = $batch->documents->first();
        $company = Company::where('cnpj', '34504257000100')->firstOrFail();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionDisabled('approve');
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewCteEmissionBatch::class])
            ->assertSee($document->register->vehicle_plate)
            ->assertTableColumnVisible('register.vehicle_plate')
            ->assertTableColumnHidden('register.vehicle_id')
            ->assertTableColumnHidden('cte_number')
            ->assertSee('NOVA SEGURADORA')
            ->callTableAction('resolveSender', $document, ['company_id' => $company->id])
            ->assertHasNoTableActionErrors()
            ->assertSee('CW TECHNOLOGY LTDA');
        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionEnabled('approve')
            ->callAction('approve')
            ->assertHasNoActionErrors();
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $batch->fresh(), 'pageClass' => ViewCteEmissionBatch::class])
            ->assertTableColumnVisible('cte_number')
            ->assertTableColumnVisible('register.vehicle_id');
    }

    private function batch(string $insurance, string $destination = 'OSASCO'): CteEmissionBatch
    {
        $register = Register::factory()->create(['origin_city' => 'OSASCO', 'destination_city' => $destination, 'insurance' => $insurance]);

        return app(CreateCteEmissionBatch::class)->handle(collect([$register]), $this->user, 'live');
    }
}
