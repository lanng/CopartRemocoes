<?php

namespace Tests\Support;

use App\Enums\CteDocumentStatusEnum;
use App\Models\City;
use App\Models\Company;
use App\Models\CteDocument;
use App\Models\Register;
use App\Models\User;
use App\Services\Cte\ApproveCteEmissionBatch;
use App\Services\Cte\CreateCteEmissionBatch;
use Illuminate\Support\Facades\Queue;

/**
 * Mundo de dados do emissor direto para testes: cidades IBGE (com as
 * ambiguidades reais), empresas do cadastro e um lote aprovado no modo direct.
 */
trait BuildsCteWorld
{
    protected function seedCteWorld(): void
    {
        foreach ([
            ['ibge_code' => '3504008', 'name' => 'Assis', 'state' => 'SP'],
            ['ibge_code' => '3523107', 'name' => 'Itaquaquecetuba', 'state' => 'SP'],
            ['ibge_code' => '3539103', 'name' => 'Pirapora do Bom Jesus', 'state' => 'SP'],
            ['ibge_code' => '3535309', 'name' => 'Palmital', 'state' => 'SP'],
            ['ibge_code' => '4117800', 'name' => 'Palmital', 'state' => 'PR'],
            ['ibge_code' => '3548906', 'name' => 'São Carlos', 'state' => 'SP'],
        ] as $city) {
            City::query()->create($city);
        }

        Company::query()->create([
            'name' => 'TOKIO MARINE SEGURADORA S A VEICULOS TMS',
            'cnpj' => '33164021000100',
            'ie' => '110794302111',
            'street' => 'RUA SAMPAIO VIANA',
            'number' => '44',
            'district' => 'PARAISO',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'zipcode' => '04004902',
            'ibge_code' => '3550308',
            'is_active' => true,
        ]);

        Company::query()->create([
            'name' => 'Copart do Brasil Organizacao de Leiloes Ltda',
            'cnpj' => '14517191000178',
            'ie' => '379121178113',
            'street' => 'Estrada Muranaka',
            'number' => '199',
            'district' => 'Jd. Novo Horizonte',
            'city' => 'Itaquaquecetuba',
            'state' => 'SP',
            'zipcode' => '08597230',
            'ibge_code' => '3523107',
            'is_active' => true,
        ]);
    }

    protected function liveDirectDocument(): CteDocument
    {
        config(['cte.emitter' => 'direct']);

        Queue::fake();

        $user = User::factory()->create();

        $register = Register::factory()->create([
            'origin_city' => 'Pirapora',
            'destination_city' => 'Itaquaquecetuba',
            'insurance' => 'tokio marine seguradora s a veiculos tms',
            'vehicle_model' => 'AMAROK',
            'vehicle_plate' => 'BDA4E98',
            'payment_code' => 'T718089',
            'fipe_value' => '139329.00',
            'value' => '898.45',
        ]);

        $batch = app(CreateCteEmissionBatch::class)->handle(collect([$register]), $user, 'live');

        app(ApproveCteEmissionBatch::class)->handle($batch, $user);

        /** @var CteDocument $document */
        $document = $batch->documents()->firstOrFail();

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $document->status);
        $this->assertSame('direct', $document->emitter);

        return $document;
    }
}
