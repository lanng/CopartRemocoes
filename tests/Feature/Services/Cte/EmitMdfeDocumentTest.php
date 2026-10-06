<?php

namespace Tests\Feature\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Services\Cte\EmitMdfeDocument;
use App\Services\Dfe\MdfeSoapClient;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\SefazResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class EmitMdfeDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.cuf' => '35',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.emit.ie' => '150046654114',
            'dfe.emit.xNome' => 'FERNANDES & FERNANDES LTDA',
            'dfe.emit.xFant' => 'AUTO SOCORRO ESTRELA',
            'dfe.emit.xLgr' => 'RUA PRUDENTE DE MORAES',
            'dfe.emit.nro' => '130',
            'dfe.emit.xBairro' => 'PQ RES ITAMARATI',
            'dfe.emit.cMun' => '3500105',
            'dfe.emit.xMun' => 'Adamantina',
            'dfe.emit.cep' => '17800000',
            'dfe.emit.uf' => 'SP',
            'dfe.mdfe' => [
                'version' => '3.00', 'series' => '1', 'tp_emit' => '1', 'tp_emis' => '1',
                'rntrc' => '45963122', 'numbering_start' => 900000021,
                'xsd_path' => resource_path('xsds/mdfe'), 'qr_base_url' => 'https://dfe-portal.svrs.rs.gov.br/mdfe/qrCode',
                'insurer_name' => '', 'insurer_cnpj' => '', 'policy_number' => '', 'production_enabled' => false,
            ],
        ]);
        Storage::fake('dfe');
        $certificate = DfeTestCertificate::create();
        app()->instance(SefazCertificate::class, new SefazCertificate($certificate['path'], $certificate['password']));
    }

    public function test_authorizes_direct_mdfe_persists_mdfeproc_and_keeps_audit_artifacts(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $document = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::QUEUED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'snapshot' => $this->snapshot(),
        ]);
        $client = Mockery::mock(MdfeSoapClient::class);
        $client->shouldReceive('consult')->never();
        $client->shouldReceive('transmit')->once()->andReturnUsing(fn (): SefazResponse => new SefazResponse(
            cStat: '100',
            xMotivo: 'Autorizado o uso do MDF-e',
            protocol: '135260000000021',
            rawXml: '<retMDFeRecepcaoSinc xmlns="http://www.portalfiscal.inf.br/mdfe"><protMDFe versao="3.00"><infProt>'
                .'<tpAmb>2</tpAmb><verAplic>SVRS1</verAplic><chMDFe>35261012563112000130580010090000021123456788</chMDFe>'
                .'<dhRecbto>2026-10-05T12:00:00-03:00</dhRecbto><nProt>135260000000021</nProt>'
                .'<cStat>100</cStat><xMotivo>Autorizado o uso do MDF-e</xMotivo>'
                .'</infProt></protMDFe></retMDFeRecepcaoSinc>',
        ));
        app()->instance(MdfeSoapClient::class, $client);

        $result = app(EmitMdfeDocument::class)->attempt($document);

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $result->status);
        $this->assertSame('135260000000021', $result->protocol);
        $this->assertSame('100', $result->fiscal_status_code);
        $this->assertNotNull($result->payload_xml);
        $this->assertNotNull($result->xml_path);
        $this->assertTrue(Storage::disk('dfe')->exists($result->xml_path));
        $this->assertSame(hash('sha256', Storage::disk('dfe')->get($result->xml_path)), $result->sha256);
    }

    public function test_blocks_production_transmission_until_the_explicit_safety_flag_is_enabled(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.mdfe.production_enabled' => false]);
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::QUEUED,
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'snapshot' => [],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('produção está bloqueada');

        app(EmitMdfeDocument::class)->attempt($document);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'issue_at' => '2026-10-05T12:00:00-03:00',
            'ciot' => '520032956638',
            'ciot_responsible_cnpj' => '12563112000130',
            'rntrc' => '45963122',
            'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
            'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            'loading_cities' => [['ibge' => '3534609', 'name' => 'Osvaldo Cruz']],
            'route_ufs' => [],
            'destinations' => [[
                'ibge' => '3508504', 'name' => 'Caçapava',
                'cte_access_keys' => ['35260912563112000130570010000028171839975374'],
            ]],
            'inf_lotacao' => ['loading_cep' => '14810539', 'unloading_cep' => '06550000'],
            'payment' => [
                'payer_name' => 'COPART DO BRASIL ORGANIZACAO DE LEILOES LTDA',
                'payer_cnpj' => '14517191000330',
                'components' => [['type' => '04', 'value' => '1000.00']],
                'contract_value' => '1000.00',
                'method' => '0',
                'bank_code' => '033',
                'bank_agency' => '0073',
            ],
            'vehicle' => [
                'plate' => 'ABC1234', 'renavam' => '12345678901', 'tare_kg' => 8000,
                'capacity_kg' => 12000, 'capacity_m3' => null, 'type_rod' => '03',
                'body_type' => '00', 'uf' => 'SP', 'drivers' => [['name' => 'Motorista Fixture', 'cpf' => '12345678909']],
                'trailers' => [],
            ],
            'cargo' => ['value' => '50000.00', 'weight_kg' => '4000.0000', 'predominant_product' => 'VEICULOS', 'type' => '05'],
            'insurance' => [
                'insurer_name' => 'Seguradora Fixture', 'insurer_cnpj' => '11111111111111',
                'policy_number' => 'FIXTURE-001', 'endorsements' => ['AVERBACAO-FIXTURE'], 'source' => 'homologation_fixture',
            ],
        ];
    }
}
