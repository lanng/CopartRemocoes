<?php

namespace Tests\Feature\Services\Dfe;

use App\Models\City;
use App\Models\MdfeDocument;
use App\Services\Dfe\BuildMdfeClosureEvent;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\XmlSigner;
use Carbon\CarbonImmutable;
use DomainException;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class BuildMdfeClosureEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['dfe.mdfe.xsd_path' => resource_path('xsds/mdfe'), 'dfe.tp_amb' => '2', 'dfe.cuf' => '35']);
    }

    public function test_builds_a_schema_valid_sp_closure_event_with_actual_closure_city(): void
    {
        $document = new MdfeDocument(['access_key' => str_repeat('1', 44), 'protocol' => '935260000000001']);
        $city = new City(['ibge_code' => '4106902', 'name' => 'Curitiba', 'state' => 'PR']);
        $closedAt = CarbonImmutable::parse('2026-10-05T14:30:00-03:00');

        config(['dfe.emit.cnpj' => '12563112000130']);
        $built = app(BuildMdfeClosureEvent::class)->handle($document, $city, $closedAt);

        $this->assertSame('ID110112'.str_repeat('1', 44).'01', $built->eventId);
        $this->assertSame('41', $built->cUf);
        $this->assertSame('4106902', $built->cMun);
        $this->assertStringContainsString('<cOrgao>35</cOrgao>', $built->xml);
        $this->assertStringContainsString('<detEvento versaoEvento="3.00"><evEncMDFe>', $built->xml);
        $this->assertStringNotContainsString('evEncMDFe versao=', $built->xml);
        $this->assertStringNotContainsString('indEncPorTerceiro', $built->xml);
        $certificate = DfeTestCertificate::create();
        $signed = (new XmlSigner(new SefazCertificate($certificate['path'], $certificate['password'])))->sign(
            $built->xml,
            'infEvento',
            'http://www.portalfiscal.inf.br/mdfe',
            $built->eventId,
        );
        app(\App\Services\Dfe\XsdValidator::class)->validateMdfeEvent($signed);
        app(\App\Services\Dfe\XsdValidator::class)->validateMdfeClosureDetail(
            '<evEncMDFe xmlns="http://www.portalfiscal.inf.br/mdfe"><descEvento>Encerramento</descEvento>'
            .'<nProt>935260000000001</nProt><dtEnc>2026-10-05</dtEnc><cUF>41</cUF><cMun>4106902</cMun></evEncMDFe>',
        );
    }

    public function test_refuses_to_build_closure_without_authorization_protocol(): void
    {
        $document = new MdfeDocument(['access_key' => str_repeat('1', 44)]);
        $city = new City(['ibge_code' => '4106902', 'name' => 'Curitiba', 'state' => 'PR']);

        $this->expectException(DomainException::class);
        app(BuildMdfeClosureEvent::class)->handle($document, $city);
    }

    public function test_specific_closure_schema_rejects_version_attribute_on_detail(): void
    {
        $xml = '<evEncMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00">'
            .'<descEvento>Encerramento</descEvento><nProt>935260000000001</nProt>'
            .'<dtEnc>2026-10-05</dtEnc><cUF>41</cUF><cMun>4106902</cMun></evEncMDFe>';

        $this->expectException(DomainException::class);
        app(\App\Services\Dfe\XsdValidator::class)->validateMdfeClosureDetail($xml);
    }
}
