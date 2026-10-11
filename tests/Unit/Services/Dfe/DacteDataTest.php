<?php

namespace Tests\Unit\Services\Dfe;

use App\Services\Dfe\DacteData;
use Tests\TestCase;

class DacteDataTest extends TestCase
{
    public function test_parses_the_vehicle_gabarito_proc(): void
    {
        $dacte = DacteData::fromProc((string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml'));

        $this->assertSame('35260912563112000130570010000029621321135813', $dacte->accessKey);
        $this->assertSame('2962', $dacte->cteNumber);
        $this->assertSame('1', $dacte->serie);
        $this->assertSame('1', $dacte->tpAmb);
        $this->assertSame('135264976797670', $dacte->protocol);
        $this->assertSame('100', $dacte->cStat);
        $this->assertFalse($dacte->isHomolog());

        $this->assertSame('FERNANDES & FERNANDES LTDA', $dacte->emit['xNome']);
        $this->assertSame('TOKIO MARINE SEGURADORA S.A', $dacte->rem['xNome']);
        $this->assertSame('Copart do Brasil Organizacao de Leiloes Ltda', $dacte->dest['xNome']);

        $this->assertSame('898.45', $dacte->vTPrest);
        $this->assertCount(1, $dacte->components);
        $this->assertSame('FRETE', $dacte->components[0]['xNome']);

        $this->assertSame('AMAROK-PLACA:BDA4E98', $dacte->cargo['proPred']);
        $this->assertSame('T718089', $dacte->cargo['xOutCat']);
        $this->assertSame('2000.0000', $dacte->cargo['qCarga']);
        $this->assertSame('01', $dacte->cargo['cUnid']);

        $this->assertSame('outros', $dacte->document['kind']);
        $this->assertSame('CARTA DE REMOCAO', $dacte->document['descOutros']);

        $this->assertSame('45963122', $dacte->rntrc);
        $this->assertSame('0', $dacte->tpCte);
        $this->assertSame('0', $dacte->tpServ);
        $this->assertSame('90', $dacte->cst);
        $this->assertSame('1', $dacte->indSn);
        $this->assertSame('3504008', $dacte->originIbge);
        $this->assertSame('3523107', $dacte->destinationIbge);
        $this->assertStringContainsString('nfe.fazenda.sp.gov.br/CTeConsulta/qrCode', (string) $dacte->qrUrl);
        $this->assertSame('Assis', $dacte->originCity);
        $this->assertSame('Itaquaquecetuba', $dacte->destinationCity);
    }

    public function test_parses_the_tank_gabarito_with_the_nfe_branch(): void
    {
        $dacte = DacteData::fromProc((string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2963.xml'));

        $this->assertSame('nfe', $dacte->document['kind']);
        $this->assertSame('35260945483450002164550010004300881365784943', $dacte->document['chave']);
        $this->assertSame('2026-09-30', $dacte->document['dPrev']);
        $this->assertSame('04', $dacte->cargo['cUnid']);
        $this->assertSame('LITROS', $dacte->cargo['tpMed']);
        $this->assertSame('21879.0000', $dacte->cargo['qCarga']);
        $this->assertSame('ETANOL HIDRATADO COMBUSTIVEL', $dacte->cargo['proPred']);
        $this->assertSame([], $dacte->components);
    }

    public function test_parses_multiple_nfe_references_and_the_physical_receiver(): void
    {
        $proc = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2963.xml');
        $firstReference = '<infNFe><chave>35260945483450002164550010004300881365784943</chave><dPrev>2026-09-30</dPrev></infNFe>';
        $references = '<infNFe><chave>35260945483450002164550010004300881365784943</chave><dPrev>2026-10-01</dPrev></infNFe>'
            .'<infNFe><chave>35261011111111111111550010000000021000000027</chave><dPrev>2026-10-01</dPrev></infNFe>';
        $receiver = '<receb><CPF>12345678901</CPF><xNome>Recebedor físico da carga</xNome>'
            .'<enderReceb><xLgr>Rua da Entrega</xLgr><nro>300</nro><xCpl>Portão 2</xCpl><xBairro>Industrial</xBairro>'
            .'<cMun>3506003</cMun><xMun>Campinas</xMun><CEP>13000000</CEP><UF>SP</UF></enderReceb></receb>';
        $proc = str_replace($firstReference, $references, $proc);
        $proc = str_replace('</rem><dest>', '</rem>'.$receiver.'<dest>', $proc);

        $dacte = DacteData::fromProc($proc);

        $this->assertCount(2, $dacte->documents);
        $this->assertSame($dacte->documents[0], $dacte->document);
        $this->assertSame('35260945483450002164550010004300881365784943', $dacte->documents[0]['chave']);
        $this->assertSame('35261011111111111111550010000000021000000027', $dacte->documents[1]['chave']);
        $this->assertSame('12345678901', $dacte->receb['CPF']);
        $this->assertSame('Recebedor físico da carga', $dacte->receb['xNome']);
        $this->assertSame('Rua da Entrega', $dacte->receb['xLgr']);
        $this->assertSame('Portão 2', $dacte->receb['xCpl']);
    }

    public function test_flags_the_homologacao_ambiente(): void
    {
        $dacte = DacteData::fromProc((string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-homolog-autorizado.xml'));

        $this->assertTrue($dacte->isHomolog());
        $this->assertSame('900000001', $dacte->cteNumber);
    }
}
