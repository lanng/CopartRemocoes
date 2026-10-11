<?php

namespace Tests\Feature\Services\Dfe;

use App\Services\Dfe\DamdfeData;
use App\Services\Dfe\GenerateDamdfePdf;
use App\Services\Dfe\MdfeCode128cSvg;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateDamdfePdfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dfe');
    }

    public function test_extracts_damdfe_data_from_an_authorized_mdfe_proc(): void
    {
        $data = DamdfeData::fromProc($this->authorizedProc());

        $this->assertSame(str_repeat('1', 44), $data['access_key']);
        $this->assertSame('900000001', $data['number']);
        $this->assertSame('2', $data['environment']);
        $this->assertSame('135260000000001', $data['protocol']);
        $this->assertSame('2026-10-05T12:01:00-03:00', $data['authorized_at']);
        $this->assertSame('FERNANDES & FERNANDES LTDA', $data['emitter']['name']);
        $this->assertSame('123456789012', $data['ciot']);
        $this->assertSame('VEICULOS', $data['cargo']['product']);
        $this->assertSame('https://example.test/mdfe/qr', $data['qr_url']);
        $this->assertSame('11222333000181', $data['insurance']['insurer_cnpj']);
        $this->assertSame('1', $data['insurance']['responsible']);
        $this->assertTrue($data['homologation']);
    }

    public function test_extracts_multiple_cte_references_for_the_two_column_document_list(): void
    {
        $data = DamdfeData::fromProc($this->authorizedProc(cteCount: 13, plate: 'PUC8E55'));

        $this->assertCount(13, $data['cte_access_keys']);
        $this->assertSame('13', $data['cargo']['cte_count']);
        $this->assertSame('PUC8E55', $data['vehicle']['plate']);

        $path = app(GenerateDamdfePdf::class)->generateForProc(
            str_repeat('1', 44),
            $this->authorizedProc(cteCount: 13, plate: 'PUC8E55'),
        );

        $this->assertTrue(Storage::disk('dfe')->exists($path));
    }

    public function test_generates_the_single_cte_alcohol_manifest_layout(): void
    {
        $xml = $this->authorizedProc(product: 'ALCOOL ETILICO', plate: 'EGK1831');
        $data = DamdfeData::fromProc($xml);

        $this->assertCount(1, $data['cte_access_keys']);
        $this->assertSame('ALCOOL ETILICO', $data['cargo']['product']);
        $this->assertSame('08', $data['cargo']['type']);
        $this->assertSame('EGK1831', $data['vehicle']['plate']);

        $path = app(GenerateDamdfePdf::class)->generateForProc(str_repeat('1', 44), $xml);

        $this->assertStringStartsWith('%PDF-', Storage::disk('dfe')->get($path));
    }

    public function test_renders_a_visual_fixture_preview_without_storing_it_as_a_fiscal_document(): void
    {
        $pdf = app(GenerateDamdfePdf::class)->renderVisualPreviewForProc(
            str_repeat('1', 44),
            $this->authorizedProc(cteCount: 13, plate: 'PUC8E55'),
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame([], Storage::disk('dfe')->allFiles());
    }

    public function test_generates_and_stores_versioned_damdfe_pdf_from_authorized_proc(): void
    {
        $path = app(GenerateDamdfePdf::class)->generateForProc(str_repeat('1', 44), $this->authorizedProc());

        $this->assertSame('mdfe/'.str_repeat('1', 44).'/damdfe.pdf', $path);
        $this->assertTrue(Storage::disk('dfe')->exists($path));
        $this->assertStringStartsWith('%PDF-', Storage::disk('dfe')->get($path));

        $nextPath = app(GenerateDamdfePdf::class)->generateForProc(str_repeat('1', 44), $this->authorizedProc());

        $this->assertSame('mdfe/'.str_repeat('1', 44).'/damdfe-2.pdf', $nextPath);
    }

    public function test_damdfe_requires_an_authorized_proc(): void
    {
        $this->expectException(DomainException::class);

        DamdfeData::fromProc(str_replace('<cStat>100</cStat>', '<cStat>204</cStat>', $this->authorizedProc()));
    }

    public function test_code128c_accepts_only_the_44_digit_mdfe_key(): void
    {
        $svg = app(MdfeCode128cSvg::class)->render(str_repeat('1', 44));

        $this->assertStringContainsString('CODE-128C '.str_repeat('1', 44), $svg);
        $this->assertStringContainsString('<svg', $svg);

        $this->expectException(DomainException::class);
        app(MdfeCode128cSvg::class)->render('123');
    }

    protected function authorizedProc(int $cteCount = 1, string $product = 'VEICULOS', string $plate = 'ABC1234'): string
    {
        $key = str_repeat('1', 44);
        $cargoType = $product === 'ALCOOL ETILICO' ? '08' : '05';
        $cteXml = '';

        for ($index = 0; $index < $cteCount; $index++) {
            $cteKey = str_repeat('3', 40).str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $cteXml .= '<infCTe><chCTe>'.$cteKey.'</chCTe></infCTe>';
        }

        return <<<XML
        <mdfeProc xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00">
          <MDFe><infMDFe Id="MDFe{$key}" versao="3.00">
            <ide><cUF>35</cUF><tpAmb>2</tpAmb><mod>58</mod><serie>1</serie><nMDF>900000001</nMDF><dhEmi>2026-10-05T12:00:00-03:00</dhEmi><UFIni>SP</UFIni><UFFim>SP</UFFim><infMunCarrega><cMunCarrega>3534609</cMunCarrega><xMunCarrega>Osvaldo Cruz</xMunCarrega></infMunCarrega></ide>
            <emit><CNPJ>12563112000130</CNPJ><IE>150046654114</IE><xNome>FERNANDES &amp; FERNANDES LTDA</xNome><enderEmit><xLgr>RUA PRUDENTE DE MORAES</xLgr><nro>130</nro><xBairro>PQ RES ITAMARATI</xBairro><cMun>3500105</cMun><xMun>Adamantina</xMun><CEP>17800000</CEP><UF>SP</UF></enderEmit></emit>
            <infModal><rodo><infANTT><RNTRC>45963122</RNTRC><infCIOT><CIOT>123456789012</CIOT></infCIOT></infANTT><veicTracao><placa>{$plate}</placa><tara>8000</tara><tpRod>03</tpRod><tpCar>00</tpCar><UF>SP</UF><condutor><xNome>Motorista Teste</xNome><CPF>12345678909</CPF></condutor></veicTracao></rodo></infModal>
            <prodPred><tpCarga>{$cargoType}</tpCarga><xProd>{$product}</xProd></prodPred>
            <infDoc><infMunDescarga><cMunDescarga>3508504</cMunDescarga><xMunDescarga>Caçapava</xMunDescarga>{$cteXml}</infMunDescarga></infDoc>
            <seg><infResp><respSeg>1</respSeg></infResp><infSeg><xSeg>Seguradora Teste</xSeg><CNPJ>11222333000181</CNPJ></infSeg><nApol>APOLICE-1</nApol><nAver>AVERBACAO-1</nAver></seg>
            <tot><qCTe>{$cteCount}</qCTe><vCarga>50000.00</vCarga><cUnid>01</cUnid><qCarga>4000.0000</qCarga></tot>
          </infMDFe><infMDFeSupl><qrCodMDFe>https://example.test/mdfe/qr</qrCodMDFe></infMDFeSupl></MDFe>
          <protMDFe versao="3.00"><infProt><tpAmb>2</tpAmb><chMDFe>{$key}</chMDFe><dhRecbto>2026-10-05T12:01:00-03:00</dhRecbto><nProt>135260000000001</nProt><cStat>100</cStat><xMotivo>Autorizado o uso do MDF-e</xMotivo></infProt></protMDFe>
        </mdfeProc>
        XML;
    }
}
