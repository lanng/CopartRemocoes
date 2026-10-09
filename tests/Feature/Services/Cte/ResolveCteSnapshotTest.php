<?php

namespace Tests\Feature\Services\Cte;

use App\Models\Company;
use App\Models\CteDocument;
use App\Services\Cte\ResolveCteSnapshot;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCteWorld;
use Tests\TestCase;

class ResolveCteSnapshotTest extends TestCase
{
    use BuildsCteWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCteWorld();
    }

    public function test_enriches_the_v1_snapshot_with_fiscal_parties_and_ibge(): void
    {
        $document = CteDocument::factory()->create([
            'snapshot' => $this->v1Snapshot('Pirapora', 'Itaquaquecetuba'),
        ]);

        $snapshot = app(ResolveCteSnapshot::class)->handle($document);

        $this->assertSame(2, $snapshot['schema_version']);

        // Alias do CITY_CONFIG + resolução case/acento-insensível na base IBGE.
        $this->assertSame(['ibge' => '3539103', 'name' => 'Pirapora do Bom Jesus', 'uf' => 'SP'], $snapshot['origin']);
        $this->assertSame(['ibge' => '3523107', 'name' => 'Itaquaquecetuba', 'uf' => 'SP'], $snapshot['destination']);

        // rem = seguradora casada por nome normalizado; dest = empresa no IBGE.
        $this->assertSame('TOKIO MARINE SEGURADORA S A VEICULOS TMS', $snapshot['rem']['xNome']);
        $this->assertSame('33164021000100', $snapshot['rem']['cnpj']);
        $this->assertSame('110794302111', $snapshot['rem']['ie']);
        $this->assertSame('Copart do Brasil Organizacao de Leiloes Ltda', $snapshot['dest']['xNome']);
        $this->assertSame('3523107', $snapshot['dest']['cMun']);

        $this->assertSame('898.45', $snapshot['value']);
        $this->assertSame('BDA4E98', $snapshot['vehicle_plate']);
    }

    public function test_prefers_sp_when_the_city_name_is_ambiguous(): void
    {
        Company::query()->create([
            'name' => 'Copart Palmital',
            'cnpj' => '14517191000555',
            'ie' => '123456789110',
            'street' => 'ROD SP-333',
            'number' => '1',
            'district' => 'CENTRO',
            'city' => 'Palmital',
            'state' => 'SP',
            'zipcode' => '19970000',
            'ibge_code' => '3535309',
            'is_active' => true,
        ]);

        $document = CteDocument::factory()->create([
            'snapshot' => $this->v1Snapshot('Palmital', 'Palmital'),
        ]);

        $snapshot = app(ResolveCteSnapshot::class)->handle($document);

        $this->assertSame('3535309', $snapshot['origin']['ibge']);
        $this->assertSame('SP', $snapshot['origin']['uf']);
    }

    public function test_resolves_accented_canonical_names_without_alias(): void
    {
        $document = CteDocument::factory()->create([
            'snapshot' => $this->v1Snapshot('SAO CARLOS', 'Itaquaquecetuba'),
        ]);

        $snapshot = app(ResolveCteSnapshot::class)->handle($document);

        $this->assertSame('3548906', $snapshot['origin']['ibge']);
        $this->assertSame('São Carlos', $snapshot['origin']['name']);
    }

    public function test_passes_a_v2_snapshot_through_untouched(): void
    {
        $v2 = ['schema_version' => 2, 'value' => '898.45', 'origin' => ['ibge' => '1']];

        $document = CteDocument::factory()->create(['snapshot' => $v2]);

        $this->assertSame($v2, app(ResolveCteSnapshot::class)->handle($document));
    }

    public function test_requires_archived_nfe_links_for_a_v3_alcohol_snapshot(): void
    {
        $document = CteDocument::factory()->create([
            'snapshot' => [
                'schema_version' => 3,
                'line' => 'tank_alcohol',
                'nfe_documents' => [['access_key' => str_repeat('1', 44)]],
            ],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('vínculos arquivados das NF-es');

        app(ResolveCteSnapshot::class)->handle($document);
    }

    public function test_fails_explicitly_when_the_insurer_is_not_registered(): void
    {
        $document = CteDocument::factory()->create([
            'snapshot' => $this->v1Snapshot('Pirapora', 'Itaquaquecetuba', 'PIER SEGURADORA'),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PIER SEGURADORA');

        app(ResolveCteSnapshot::class)->handle($document);
    }

    public function test_fails_explicitly_when_the_destination_has_no_company(): void
    {
        $document = CteDocument::factory()->create([
            'snapshot' => $this->v1Snapshot('Pirapora', 'Assis'),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('3504008');

        app(ResolveCteSnapshot::class)->handle($document);
    }

    public function test_fails_explicitly_when_the_company_is_missing_fiscal_data(): void
    {
        Company::query()->create([
            'name' => 'PIER SEGURADORA',
            'cnpj' => '12345678000199',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'is_active' => true,
        ]);

        $document = CteDocument::factory()->create([
            'snapshot' => $this->v1Snapshot('Pirapora', 'Itaquaquecetuba', 'PIER SEGURADORA'),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('street');

        app(ResolveCteSnapshot::class)->handle($document);
    }

    public function test_resolves_a_non_contributor_sender_without_ie(): void
    {
        Company::query()->where('cnpj', '33164021000100')->update(['ie' => null]);
        $document = CteDocument::factory()->create([
            'snapshot' => $this->v1Snapshot('Pirapora', 'Itaquaquecetuba'),
        ]);

        $snapshot = app(ResolveCteSnapshot::class)->handle($document);

        $this->assertNull($snapshot['rem']['ie']);
        $this->assertSame('379121178113', $snapshot['dest']['ie']);
    }

    public function test_resolves_an_exempt_sender(): void
    {
        Company::query()->where('cnpj', '33164021000100')->update(['ie' => 'ISENTO']);
        $document = CteDocument::factory()->create([
            'snapshot' => $this->v1Snapshot('Pirapora', 'Itaquaquecetuba'),
        ]);

        $snapshot = app(ResolveCteSnapshot::class)->handle($document);

        $this->assertSame('ISENTO', $snapshot['rem']['ie']);
    }

    #[DataProvider('invalidInscriptionExamples')]
    public function test_rejects_invalid_sender_ie_before_emission(string $ie): void
    {
        Company::query()->where('cnpj', '33164021000100')->update(['ie' => $ie]);
        $document = CteDocument::factory()->create([
            'snapshot' => $this->v1Snapshot('Pirapora', 'Itaquaquecetuba'),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('IE inválida');

        app(ResolveCteSnapshot::class)->handle($document);
    }

    public static function invalidInscriptionExamples(): array
    {
        return [
            'legacy non contributor marker' => ['0'],
            'legacy exempt marker' => ['1'],
            'unexpected letters' => ['123A456789'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function v1Snapshot(string $origin, string $destination, string $insurance = 'tokio marine seguradora s a veiculos tms'): array
    {
        return [
            'schema_version' => 1,
            'register_id' => 1,
            'vehicle_id' => '1146609',
            'company' => 'copart',
            'vehicle_model' => 'AMAROK',
            'vehicle_plate' => 'BDA4E98',
            'origin_city' => $origin,
            'destination_city' => $destination,
            'payment_code' => 'T718089',
            'insurance' => $insurance,
            'fipe_value' => '139329.00',
            'value' => '898.45',
        ];
    }
}
