<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Services\Ciot\BuildMdfeSnapshot;
use App\Services\Ciot\DispatchMdfeForBatch;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MdfeDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected CteEmissionBatch $batch;

    protected array $keys;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mdfe.driver_code' => '8',
            'mdfe.vehicle_code' => '12',
            'ciot.lines.vehicle_removal' => [
                'bank_code' => '104',
                'bank_agency' => '73',
                'bank_account' => '12345678',
            ],
        ]);

        $this->batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
        ]);

        $keys = [
            '35260912563112000130570010000028171839975374',
            '35260912563112000130570010000028171998887776',
        ];

        CteDocument::factory()->count(2)->sequence(
            ['access_key' => $keys[0]],
            ['access_key' => $keys[1]],
        )->create([
            'cte_emission_batch_id' => $this->batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'snapshot' => [
                'company' => 'copart',
                'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz',
                'destination_city' => 'Caçapava',
                'value' => '500.00',
                'fipe_value' => '10000.00',
            ],
        ]);

        $this->keys = $keys;

        Ciot::factory()->create([
            'cte_emission_batch_id' => $this->batch->id,
            'status' => 'issued',
            'ciot_number' => '520032956638',
            'cargo_weight_kg' => '4000.00',
            'freight_value_cents' => 100000,
            'payer_cnpj' => '14517191000925',
            'payer_name' => 'Copart Caçapava',
            'origin' => [
                'cidade' => 'Osvaldo Cruz', 'uf' => 'SP',
                'cep' => '17700-000', 'ibge' => '3534609',
            ],
            'destination' => [
                'cidade' => 'Caçapava', 'uf' => 'SP',
                'cep' => '12286-140', 'ibge' => '3508504',
            ],
        ]);
    }

    protected function deliveryPayerWithCep(): void
    {
        $delivery = \App\Models\CiotPayer::factory()->create([
            'cnpj' => '14517191000925',
            'zipcode' => '12286140',
        ]);

        Ciot::query()->where('cte_emission_batch_id', $this->batch->id)
            ->update(['delivery_payer_id' => $delivery->id]);
    }

    public function test_builds_the_snapshot_with_all_sources(): void
    {
        $this->deliveryPayerWithCep();

        $snapshot = app(BuildMdfeSnapshot::class)->handle($this->batch);

        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame($this->batch->id, $snapshot['trip_id']);
        $this->assertSame('copart', $snapshot['company']);
        $this->assertSame('Osvaldo Cruz', $snapshot['origin_city']);
        $this->assertSame('Caçapava', $snapshot['destination_city']);
        $this->assertSame('8', $snapshot['driver_code']);
        $this->assertSame('12', $snapshot['vehicle_code']);
        $this->assertSame('520032956638', $snapshot['ciot']);
        $this->assertSame('20000.00', $snapshot['cargo_value']);
        $this->assertSame('4000.00', $snapshot['cargo_weight_kg']);
        $this->assertSame('17700000', $snapshot['origin_cep']);
        $this->assertSame('12286140', $snapshot['destination_cep']);
        $this->assertSame('14517191000925', $snapshot['payment_doc']);
        $this->assertSame('Copart Caçapava', $snapshot['payment_name']);
        $this->assertSame('1000.00', $snapshot['payment_value']);
        $this->assertSame('104', $snapshot['payment_bank']);
        $this->assertSame('0073', $snapshot['payment_agency']);
        $this->assertSame($this->keys, $snapshot['cte_access_keys']);
    }

    public function test_throws_listing_every_missing_requirement(): void
    {
        $ciot = Ciot::query()->where('cte_emission_batch_id', $this->batch->id)->firstOrFail();
        $ciot->forceFill([
            'origin' => ['cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => null, 'ibge' => '3534609'],
        ])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('CEP de origem');

        app(BuildMdfeSnapshot::class)->handle($this->batch);
    }

    public function test_dispatch_creates_a_queued_mdfe_document(): void
    {
        $this->deliveryPayerWithCep();

        $mdfe = app(DispatchMdfeForBatch::class)->handle($this->batch);

        $this->assertNotNull($mdfe);
        $this->assertSame('queued', $mdfe->status->value);
        $this->assertSame('live', $mdfe->execution_mode);
        $this->assertSame('520032956638', $mdfe->snapshot['ciot']);
        $this->assertSame($this->keys, $mdfe->snapshot['cte_access_keys']);
    }

    public function test_dispatch_is_idempotent(): void
    {
        $this->deliveryPayerWithCep();

        $first = app(DispatchMdfeForBatch::class)->handle($this->batch);
        $second = app(DispatchMdfeForBatch::class)->handle($this->batch);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    public function test_dispatch_skips_without_an_issued_ciot(): void
    {
        $this->deliveryPayerWithCep();
        Ciot::query()->where('cte_emission_batch_id', $this->batch->id)
            ->update(['status' => 'draft']);

        $this->assertNull(app(DispatchMdfeForBatch::class)->handle($this->batch));
    }

    public function test_dispatch_skips_when_a_cte_is_not_authorized(): void
    {
        $this->deliveryPayerWithCep();
        CteDocument::query()->first()->update(['status' => 'queued']);

        $this->assertNull(app(DispatchMdfeForBatch::class)->handle($this->batch));
    }
}
