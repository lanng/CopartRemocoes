<?php

namespace Tests\Feature\Services\Cte;

use App\Services\Cte\BuildCteXml;
use App\Services\Dfe\AccessKey;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\XmlSigner;
use App\Services\Dfe\XsdValidator;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class BuildCteXmlTest extends TestCase
{
    use RefreshDatabase;

    protected BuildCteXml $builder;

    protected XmlSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.cte.serie' => '1',
            'dfe.numbering_start' => null,
        ]);

        // Data fixa para o dhEmi ser determinístico nos testes.
        Carbon::setTestNow('2026-10-01 12:00:00');

        // O XSD oficial do CT-e exige o ds:Signature — a validação local roda
        // sobre o XML JÁ ASSINADO (assinar → validar → transmitir; ver relatório).
        $certificate = DfeTestCertificate::create();
        config(['dfe.cert_path' => $certificate['path'], 'dfe.cert_password' => $certificate['password']]);

        $this->signer = new XmlSigner(new SefazCertificate($certificate['path'], $certificate['password']));
        $this->builder = app(BuildCteXml::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Constrói, assina e valida o XSD — devolve o BuiltCte para as asserções.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function signed(array $snapshot): \App\Services\Cte\BuiltCte
    {
        $built = $this->builder->handle($snapshot);

        $signedXml = $this->signer->sign($built->xml, 'infCte', 'http://www.portalfiscal.inf.br/cte', 'CTe'.$built->accessKey);

        (app(XsdValidator::class))->validateCte($signedXml);

        return $built;
    }

    public function test_builds_the_vehicle_line_xml_from_a_real_snapshot_and_validates_the_xsd(): void
    {
        $built = $this->signed($this->vehicleSnapshot());

        $this->assertSame(900000001, $built->number);
        $this->assertSame(1, $built->serie);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $built->cct);
        $this->assertSame(44, strlen($built->accessKey));
        $this->assertSame(AccessKey::mod11(substr($built->accessKey, 0, 43)), substr($built->accessKey, -1));

        $xml = $built->xml;

        // Cabeçalho/ide: numeração de teste, chave e ambiente derivados de tp_amb.
        $this->assertStringContainsString('<infCte Id="CTe'.$built->accessKey.'" versao="4.00">', $xml);
        $this->assertStringContainsString('<nCT>900000001</nCT>', $xml);
        $this->assertStringContainsString('<serie>1</serie>', $xml);
        $this->assertStringContainsString('<cDV>'.substr($built->accessKey, -1).'</cDV>', $xml);
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<dhEmi>2026-10-01T09:00:00-03:00</dhEmi>', $xml);
        $this->assertStringContainsString('<verProc>COPART-DFE-1.0</verProc>', $xml);
        $this->assertStringContainsString('<cMunIni>3504008</cMunIni><xMunIni>Assis</xMunIni><UFIni>SP</UFIni>', $xml);
        $this->assertStringContainsString('<cMunFim>3523107</cMunFim><xMunFim>Itaquaquecetuba</xMunFim><UFFim>SP</UFFim>', $xml);
        $this->assertStringContainsString('<indIEToma>9</indIEToma>', $xml);

        // Emissor fixo da config (spec §2.5).
        $this->assertStringContainsString('<CNPJ>12563112000130</CNPJ><IE>150046654114</IE>', $xml);
        $this->assertStringContainsString('<xNome>FERNANDES &amp; FERNANDES LTDA</xNome>', $xml);

        // Regras de homologação (§2.4): letreiro, sem IE e sem xFant nas partes
        // (o emit mantém o xFant, como no gabarito homolog autorizado).
        $this->assertSame(2, substr_count($xml, '<CNPJ>00000000000191</CNPJ>'));
        $this->assertSame(2, substr_count($xml, '<xNome>CTE EMITIDO EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL</xNome>'));
        $this->assertStringContainsString('SEM VALOR FISCAL</xNome><enderReme>', $xml);
        $this->assertStringContainsString('SEM VALOR FISCAL</xNome><enderDest>', $xml);
        $this->assertStringNotContainsString('<IE>110794302111</IE>', $xml);
        $this->assertStringContainsString('<IE>150046654114</IE>', $xml);

        // Linha veículos: vPrest com Comp FRETE, infCarga FIPE/carta, infOutros.
        $this->assertStringContainsString('<vPrest><vTPrest>898.45</vTPrest><vRec>898.45</vRec><Comp><xNome>FRETE</xNome><vComp>898.45</vComp></Comp></vPrest>', $xml);
        $this->assertStringContainsString('<vCarga>139329.00</vCarga>', $xml);
        $this->assertStringContainsString('<proPred>AMAROK-PLACA:BDA4E98</proPred>', $xml);
        $this->assertStringContainsString('<xOutCat>T718089</xOutCat>', $xml);
        $this->assertStringContainsString('<infQ><cUnid>01</cUnid><tpMed>KG</tpMed><qCarga>2000.0000</qCarga></infQ>', $xml);
        $this->assertStringContainsString('<infDoc><infOutros><tpDoc>99</tpDoc><descOutros>CARTA DE REMOCAO</descOutros><nDoc>0</nDoc><dEmi>2026-10-01</dEmi></infOutros></infDoc>', $xml);
        $this->assertStringContainsString('<infModal versaoModal="4.00"><rodo><RNTRC>45963122</RNTRC></rodo></infModal>', $xml);

        // QR de homologação, sem parâmetro sign (spec §2.4).
        $this->assertStringContainsString('<infCTeSupl xmlns="http://www.portalfiscal.inf.br/cte"><qrCodCTe><![CDATA[https://homologacao.nfe.fazenda.sp.gov.br/CTeConsulta/qrCode?chCTe='.$built->accessKey.'&tpAmb=2]]></qrCodCTe></infCTeSupl>', $xml);
    }

    public function test_builds_the_tank_line_xml_with_the_inf_nfe_branch(): void
    {
        $built = $this->signed($this->tankSnapshot());

        $xml = $built->xml;

        $this->assertStringContainsString('<vPrest><vTPrest>3725.81</vTPrest><vRec>3725.81</vRec></vPrest>', $xml);
        $this->assertStringNotContainsString('<Comp>', $xml);
        $this->assertStringContainsString('<proPred>ETANOL HIDRATADO COMBUSTIVEL</proPred>', $xml);
        $this->assertStringContainsString('<infQ><cUnid>04</cUnid><tpMed>LITROS</tpMed><qCarga>21879.0000</qCarga></infQ>', $xml);
        $this->assertStringContainsString('<infDoc><infNFe><chave>35260945483450002164550010004300881365784943</chave><dPrev>2026-10-02</dPrev></infNFe></infDoc>', $xml);
        $this->assertStringNotContainsString('<xOutCat>', $xml);
    }

    public function test_production_derives_real_parties_and_qr_from_tp_amb(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.numbering_start' => 2964]);

        $built = $this->signed($this->vehicleSnapshot());

        $xml = $built->xml;

        $this->assertStringContainsString('<nCT>2964</nCT>', $xml);
        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $xml);
        $this->assertStringContainsString('<indIEToma>1</indIEToma>', $xml);

        // Partes reais do snapshot, com IE e xFant no rem (gabarito 2962).
        $this->assertStringContainsString('<rem><CNPJ>33164021000100</CNPJ><IE>110794302111</IE><xNome>TOKIO MARINE SEGURADORA S.A</xNome><xFant>TOKIO MARINE SEGURADORA S.A</xFant>', $xml);
        $this->assertStringContainsString('<dest><CNPJ>14517191000178</CNPJ><IE>379121178113</IE><xNome>Copart do Brasil Organizacao de Leiloes Ltda</xNome>', $xml);
        $this->assertStringNotContainsString('SEM VALOR FISCAL', $xml);

        $this->assertStringContainsString('https://nfe.fazenda.sp.gov.br/CTeConsulta/qrCode?chCTe='.$built->accessKey.'&tpAmb=1', $xml);
    }

    public function test_rejects_an_incomplete_snapshot_listening_every_missing_field(): void
    {
        try {
            $this->builder->handle(['line' => 'vehicle_removal', 'schema_version' => 2]);
            $this->fail('Expected DomainException.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('value', $exception->getMessage());
            $this->assertStringContainsString('origin.ibge', $exception->getMessage());
            $this->assertStringContainsString('vehicle_model', $exception->getMessage());
        }
    }

    public function test_rejects_a_v1_snapshot_without_the_fiscal_party_blocks(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Snapshot v1');

        $this->builder->handle(['schema_version' => 1, 'value' => '898.45']);
    }

    public function test_allocates_distinct_numbers_across_documents(): void
    {
        $first = $this->builder->handle($this->vehicleSnapshot());
        $second = $this->builder->handle($this->vehicleSnapshot());

        $this->assertSame(900000001, $first->number);
        $this->assertSame(900000002, $second->number);
        $this->assertNotSame($first->accessKey, $second->accessKey);
    }

    /**
     * Snapshot espelhando o gabarito autorizado 2962 (linha veículos).
     *
     * @return array<string, mixed>
     */
    protected function vehicleSnapshot(): array
    {
        return [
            'schema_version' => 2,
            'line' => 'vehicle_removal',
            'register_id' => 1,
            'value' => '898.45',
            'fipe_value' => '139329.00',
            'vehicle_model' => 'AMAROK',
            'vehicle_plate' => 'BDA4E98',
            'payment_code' => 'T718089',
            'origin' => ['ibge' => '3504008', 'name' => 'Assis', 'uf' => 'SP'],
            'destination' => ['ibge' => '3523107', 'name' => 'Itaquaquecetuba', 'uf' => 'SP'],
            'rem' => [
                'cnpj' => '33164021000100',
                'ie' => '110794302111',
                'xNome' => 'TOKIO MARINE SEGURADORA S.A',
                'xLgr' => 'RUA SAMPAIO VIANA',
                'nro' => '44',
                'xBairro' => 'PARAISO',
                'cMun' => '3550308',
                'xMun' => 'Sao Paulo',
                'cep' => '04004902',
                'uf' => 'SP',
            ],
            'dest' => [
                'cnpj' => '14517191000178',
                'ie' => '379121178113',
                'xNome' => 'Copart do Brasil Organizacao de Leiloes Ltda',
                'xLgr' => 'Estrada Muranaka',
                'nro' => '199',
                'xBairro' => 'Jd. Novo Horizonte',
                'cMun' => '3523107',
                'xMun' => 'Itaquaquecetuba',
                'cep' => '08597230',
                'uf' => 'SP',
            ],
        ];
    }

    /**
     * Snapshot espelhando o gabarito autorizado 2963 (linha tanque).
     *
     * @return array<string, mixed>
     */
    protected function tankSnapshot(): array
    {
        return [
            'schema_version' => 2,
            'line' => 'tank_alcohol',
            'register_id' => 2,
            'value' => '3725.81',
            'cargo_value' => '54294.71',
            'cargo_liters' => '21879',
            'nfe_key' => '35260945483450002164550010004300881365784943',
            'dprev' => '2026-10-02',
            'origin' => ['ibge' => '3541802', 'name' => 'Queiroz', 'uf' => 'SP'],
            'destination' => ['ibge' => '3525102', 'name' => 'Jardinopolis', 'uf' => 'SP'],
            'rem' => [
                'cnpj' => '45483450002164',
                'ie' => '566051683117',
                'xNome' => 'CLEALCO ACUCAR E ALCOOL S/A EM RECUPERACAO JUDICIAL',
                'xFant' => 'CLEALCO ACUCAR E ALCOOL S/A EM RECUPERAC',
                'xLgr' => 'FAZENDA POUSO ALEGRE,',
                'nro' => 'S/N',
                'xBairro' => 'ZONA RURAL',
                'cMun' => '3541802',
                'xMun' => 'Queiroz',
                'cep' => '17590000',
                'uf' => 'SP',
            ],
            'dest' => [
                'cnpj' => '48580847000208',
                'ie' => '399116523112',
                'xNome' => 'AVANT COMBUSTIVEIS LTDA',
                'xLgr' => 'R BRASIL ALTO FURQUINI',
                'nro' => '401',
                'xBairro' => 'BAIRRO DISTRITO INDUSTRIAL ADI',
                'cMun' => '3525102',
                'xMun' => 'Jardinopolis',
                'cep' => '14690000',
                'uf' => 'SP',
            ],
        ];
    }
}
