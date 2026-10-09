<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Jobs\EmitCiotJob;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\Company;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Services\Ciot\PrepareMdfeDocument;
use App\Services\Ciot\QueueDirectMdfeEmission;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrepareMdfeDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.company.cnpj' => '12563112000130',
            'ciot.company.rntrc' => '45963122',
            'ciot.lines.vehicle_removal.bank_code' => '756',
            'ciot.lines.vehicle_removal.bank_agency' => '0001',
            'dfe.tp_amb' => '2',
            'dfe.ciot_synthetic_homologation_enabled' => true,
            'dfe.emit.cnpj' => '12563112000130',
        ]);
    }

    public function test_ignores_legacy_plate_override_before_validating_the_selected_ctes(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Selecione ao menos um CT-e autorizado.');

        app(PrepareMdfeDocument::class)->handle(['vehicle_plate' => 'INVALID']);
    }

    public function test_it_creates_a_synthetic_ciot_for_tank_alcohol_and_queues_the_mdfe_without_antt(): void
    {
        Http::fake();
        Queue::fake();

        $document = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
            'workflow_origin' => 'controlled_v1',
            'access_key' => str_repeat('2', 44),
            'snapshot' => [
                'schema_version' => 2,
                'line' => CiotLineEnum::TankAlcohol->value,
                'value' => '1200.00',
                'cargo_weight_kg' => '25000.000',
                'cargo_value' => '30000.00',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            ],
        ]);
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $vehicle = CiotVehicle::factory()->forTank()->create();
        $driver = MdfeDriver::factory()->create(['line' => CiotLineEnum::TankAlcohol->value]);
        $ciotData = $this->newCiotFormData($payer, $vehicle);
        $ciotData['line'] = CiotLineEnum::TankAlcohol->value;
        $ciotData['status'] = CiotStatusEnum::ISSUED->value;

        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldReceive('handleForDocuments')
            ->once()
            ->withArgs(function (Collection $documents, Ciot $ciot, array $endorsements, array $routeUfs): bool {
                $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
                $this->assertSame(CiotLineEnum::TankAlcohol, $ciot->line);
                $this->assertTrue($ciot->response['homologation_fixture']['synthetic']);
                $this->assertCount(1, $documents);
                $this->assertSame(['AVERBACAO-ALCOOL-001'], $endorsements);
                $this->assertSame([], $routeUfs);

                return true;
            })
            ->andReturnUsing(fn (Collection $documents, Ciot $ciot): MdfeDocument => MdfeDocument::factory()->create([
                'cte_emission_batch_id' => null,
                'ciot_id' => $ciot->id,
            ]));
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $result = app(PrepareMdfeDocument::class)->handle([
            'cte_document_ids' => [$document->id],
            'line' => CiotLineEnum::TankAlcohol->value,
            'driver_id' => $driver->id,
            'ciot_mode' => 'synthetic',
            'new_ciot' => $ciotData,
            'endorsements' => [['number' => 'AVERBACAO-ALCOOL-001']],
        ]);

        $this->assertSame(CiotStatusEnum::ISSUED, $result->ciot->status);
        $this->assertSame('99'.str_pad((string) $result->ciot->id, 10, '0', STR_PAD_LEFT), $result->ciot->ciot_number);
        $this->assertNull($result->ciot->protocol);
        $this->assertNotNull($result->mdfe);
        $this->assertNull($result->ciotEmissionResult);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_synthetic_ciot_creation_outside_sefaz_homologation(): void
    {
        config(['dfe.tp_amb' => '1']);

        $document = $this->controlledVehicleDocument();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $vehicle = CiotVehicle::factory()->forRemoval()->create();
        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldNotReceive('handleForDocuments');
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('somente em homologação');

        try {
            app(PrepareMdfeDocument::class)->handle([
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'synthetic',
                'new_ciot' => $this->newCiotFormData($payer, $vehicle),
                'endorsements' => [['number' => 'AVERBACAO-001']],
            ]);
        } finally {
            $this->assertSame(0, Ciot::query()->count());
        }
    }

    public function test_it_rejects_reusing_a_synthetic_ciot_when_the_homologation_option_is_disabled(): void
    {
        config([
            'dfe.tp_amb' => '1',
            'dfe.ciot_synthetic_homologation_enabled' => false,
        ]);

        $document = $this->controlledVehicleDocument();
        $ciot = Ciot::factory()->issued()->create([
            'line' => CiotLineEnum::VehicleRemoval,
            'response' => ['homologation_fixture' => ['synthetic' => true]],
        ]);
        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldNotReceive('handleForDocuments');
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        try {
            app(PrepareMdfeDocument::class)->handle([
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'existing',
                'ciot_id' => $ciot->id,
                'endorsements' => [['number' => 'AVERBACAO-001']],
            ]);
            $this->fail('Expected DomainException.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('recurso habilitado em homologação', $exception->getMessage());
        }
    }

    public function test_it_emits_a_new_ciot_before_queuing_the_mdfe(): void
    {
        Queue::fake();

        $document = $this->controlledVehicleDocument();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);

        $this->fakeAnttAcceptingTheDeclaration('520032959999');

        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldReceive('handleForDocuments')
            ->once()
            ->withArgs(function (Collection $documents, Ciot $ciot, array $endorsements, array $routeUfs): bool {
                $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
                $this->assertCount(1, $documents);
                $this->assertSame(['AVERBACAO-001'], $endorsements);
                $this->assertSame([], $routeUfs);

                return true;
            })
            ->andReturnUsing(fn (Collection $documents, Ciot $ciot): MdfeDocument => MdfeDocument::factory()->create([
                'cte_emission_batch_id' => null,
                'ciot_id' => $ciot->id,
            ]));
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $result = app(PrepareMdfeDocument::class)->handle([
            'cte_document_ids' => [$document->id],
            'ciot_mode' => 'new',
            'new_ciot' => $this->newCiotFormData($payer, $tractor),
            'endorsements' => [['number' => 'AVERBACAO-001']],
        ]);

        $this->assertSame(CiotStatusEnum::ISSUED, $result->ciot->status);
        $this->assertNotNull($result->mdfe);
        $this->assertSame($result->ciot->id, $result->mdfe->ciot_id);
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_queue_an_mdfe_when_the_new_ciot_is_rejected(): void
    {
        Queue::fake();

        $document = $this->controlledVehicleDocument();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '520032959998'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '217',
                'Mensagem' => 'Dados rejeitados',
                'IdOperacaoTransporte' => '520032959998',
            ], 200),
        ]);

        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldNotReceive('handleForDocuments');
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $result = app(PrepareMdfeDocument::class)->handle([
            'cte_document_ids' => [$document->id],
            'ciot_mode' => 'new',
            'new_ciot' => $this->newCiotFormData($payer, $tractor),
            'endorsements' => [['number' => 'AVERBACAO-001']],
        ]);

        $this->assertSame(CiotStatusEnum::FAILED, $result->ciot->status);
        $this->assertNull($result->mdfe);
        $this->assertSame(0, MdfeDocument::query()->count());
        Queue::assertNotPushed(EmitCiotJob::class);
    }

    public function test_it_rejects_legacy_cte_ids_before_creating_a_new_ciot(): void
    {
        $document = $this->controlledVehicleDocument(workflowOrigin: null);
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);
        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldNotReceive('handleForDocuments');
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fluxo controlado');

        try {
            app(PrepareMdfeDocument::class)->handle([
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'new',
                'new_ciot' => $this->newCiotFormData($payer, $tractor),
                'endorsements' => [['number' => 'AVERBACAO-001']],
            ]);
        } finally {
            $this->assertSame(0, Ciot::query()->count());
        }
    }

    public function test_it_rejects_an_overlong_endorsement_before_emitting_a_new_ciot(): void
    {
        $document = $this->controlledVehicleDocument();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);
        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldNotReceive('handleForDocuments');
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede 40 caracteres');

        try {
            app(PrepareMdfeDocument::class)->handle([
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'new',
                'new_ciot' => $this->newCiotFormData($payer, $tractor),
                'endorsements' => [['number' => str_repeat('A', 41)]],
            ]);
        } finally {
            $this->assertSame(0, Ciot::query()->count());
        }
    }

    public function test_it_requires_an_endorsement_before_emitting_a_new_ciot(): void
    {
        $document = $this->controlledVehicleDocument();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);
        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldNotReceive('handleForDocuments');
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('averbação');

        try {
            app(PrepareMdfeDocument::class)->handle([
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'new',
                'new_ciot' => $this->newCiotFormData($payer, $tractor),
                'endorsements' => [['number' => '']],
            ]);
        } finally {
            $this->assertSame(0, Ciot::query()->count());
        }
    }

    public function test_it_lists_only_eligible_ctes_in_the_approved_order_and_open_ciots(): void
    {
        $date = now()->subDay();
        $numberNine = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '9',
            'access_key' => str_repeat('9', 44),
            'authorized_at' => $date,
        ]);
        $numberTen = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '10',
            'access_key' => str_repeat('8', 44),
            'authorized_at' => $date,
        ]);
        $numberEleven = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '11',
            'access_key' => str_repeat('7', 44),
            'authorized_at' => $date,
        ]);
        $legacy = $this->controlledVehicleDocument(workflowOrigin: null, overrides: [
            'cte_number' => '12',
            'access_key' => str_repeat('6', 44),
            'authorized_at' => $date->copy()->addDay(),
        ]);
        $queued = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '13',
            'access_key' => str_repeat('5', 44),
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);

        $lineMismatch = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '14',
            'access_key' => str_repeat('4', 44),
            'snapshot' => [
                'line' => CiotLineEnum::TankAlcohol->value,
            ],
        ]);

        $activeCiot = Ciot::factory()->issued()->create();
        $activeMdfe = MdfeDocument::factory()->create([
            'ciot_id' => $activeCiot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $activeMdfe->cteDocuments()->attach($numberNine->id, ['position' => 1]);

        $olderCiot = Ciot::factory()->issued()->create([
            'issued_at' => $date,
        ]);
        $newerCiot = Ciot::factory()->issued()->create([
            'issued_at' => $date->copy()->addDay(),
        ]);
        $closedCiot = Ciot::factory()->create([
            'status' => CiotStatusEnum::CLOSED,
        ]);
        $tankAlcoholCiot = Ciot::factory()->issued()->tankAlcohol()->create();
        $activeCiot->forceFill(['issued_at' => $date->copy()->addDays(2)])->save();
        $resumableCiot = Ciot::factory()->issued()->create([
            'issued_at' => $date->copy()->subDay(),
        ]);
        $rejectedMdfe = MdfeDocument::factory()->create([
            'ciot_id' => $resumableCiot->id,
            'status' => CteDocumentStatusEnum::REJECTED,
        ]);
        $rejectedMdfe->cteDocuments()->attach($numberNine->id, ['position' => 1]);

        $ctes = app(PrepareMdfeDocument::class)->eligibleCteDocuments(CiotLineEnum::VehicleRemoval);
        $ciots = app(PrepareMdfeDocument::class)->eligibleCiots(CiotLineEnum::VehicleRemoval);

        $this->assertSame([$numberEleven->id, $numberTen->id], $ctes->pluck('id')->all());
        $this->assertNotContains($legacy->id, $ctes->pluck('id')->all());
        $this->assertNotContains($queued->id, $ctes->pluck('id')->all());
        $this->assertNotContains($lineMismatch->id, $ctes->pluck('id')->all());
        $this->assertSame([$newerCiot->id, $olderCiot->id, $resumableCiot->id], $ciots->pluck('id')->all());
        $this->assertNotContains($activeCiot->id, $ciots->pluck('id')->all());
        $this->assertNotContains($closedCiot->id, $ciots->pluck('id')->all());
        $this->assertNotContains($tankAlcoholCiot->id, $ciots->pluck('id')->all());
    }

    public function test_batch_context_scopes_search_and_selected_cte_labels(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $otherBatch = CteEmissionBatch::factory()->create();
        $inside = $this->controlledVehicleDocument(overrides: [
            'cte_emission_batch_id' => $batch->id,
            'cte_number' => '101',
            'access_key' => str_repeat('1', 44),
        ]);
        $outside = $this->controlledVehicleDocument(overrides: [
            'cte_emission_batch_id' => $otherBatch->id,
            'cte_number' => '102',
            'access_key' => str_repeat('2', 44),
        ]);

        $service = app(PrepareMdfeDocument::class);

        $this->assertSame(
            [$inside->id],
            $service->eligibleCteDocuments(CiotLineEnum::VehicleRemoval, batchId: $batch->id)->pluck('id')->all(),
        );
        $this->assertSame(
            [$inside->id],
            $service->eligibleCteDocumentsByIds(CiotLineEnum::VehicleRemoval, [$outside->id, $inside->id], $batch->id)->pluck('id')->all(),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function controlledVehicleDocument(?string $workflowOrigin = 'controlled_v1', array $overrides = []): CteDocument
    {
        return CteDocument::factory()->authorized()->create(array_merge([
            'cte_emission_batch_id' => null,
            'register_id' => null,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'workflow_origin' => $workflowOrigin,
            'access_key' => str_repeat('1', 44),
            'snapshot' => [
                'schema_version' => 2,
                'line' => CiotLineEnum::VehicleRemoval->value,
                'value' => '500.00',
                'fipe_value' => '10000.00',
                'vehicle_model' => 'VW GOL',
                'vehicle_plate' => 'ABC1D23',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            ],
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function newCiotFormData(Company $payer, CiotVehicle $tractor): array
    {
        return [
            'status' => 'draft',
            'line' => CiotLineEnum::VehicleRemoval->value,
            'operation_type' => 'lotation',
            'payer_id' => $payer->id,
            'delivery_payer_id' => $payer->id,
            'origin' => ['cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '17700000', 'ibge' => '3534609'],
            'destination' => ['cidade' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140', 'ibge' => '3508504'],
            'distance_km' => '716',
            'freight_value' => '500.00',
            'cargo_weight_kg' => '2000',
            'vehicle_ids' => [$tractor->id],
            'travel_start_at' => today()->toDateString(),
            'travel_end_at' => today()->addDay()->toDateString(),
        ];
    }

    private function fakeAnttAcceptingTheDeclaration(string $ciotNumber): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => $ciotNumber],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados cadastrados com sucesso',
                'Protocolo' => $ciotNumber.'0001',
                'CodigoVerificador' => '0001',
                'IdOperacaoTransporte' => $ciotNumber,
            ], 200),
        ]);
    }
}
