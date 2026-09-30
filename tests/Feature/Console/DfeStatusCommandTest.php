<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class DfeStatusCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $certificate = DfeTestCertificate::create();

        config([
            'dfe.tp_amb' => '2',
            'dfe.base_url' => 'https://sefaz-hml.test/CTeWS/WS',
            'dfe.cert_path' => $certificate['path'],
            'dfe.cert_password' => $certificate['password'],
            'dfe.ca_bundle' => '/caminho/inexistente/sefaz-ca.pem',
            'dfe.log_channel' => 'null',
        ]);
    }

    public function test_reports_success_when_sefaz_returns_cstat_107(): void
    {
        Http::fake([
            'https://sefaz-hml.test/CTeWS/WS/CTeStatusServicoV4.asmx' => Http::response($this->statusResponse('107', 'Servico em Operacao'), 200),
        ]);

        $this->artisan('dfe:status')
            ->expectsOutputToContain('cStat 107')
            ->assertExitCode(0);
    }

    public function test_fails_on_an_unexpected_cstat(): void
    {
        Http::fake([
            'https://sefaz-hml.test/CTeWS/WS/CTeStatusServicoV4.asmx' => Http::response($this->statusResponse('106', 'Servico nao em Operacao'), 200),
        ]);

        $this->artisan('dfe:status')
            ->expectsOutputToContain('cStat 106')
            ->assertExitCode(1);
    }

    public function test_fails_on_connection_error(): void
    {
        Http::fake([
            '*' => Http::failedConnection(),
        ]);

        $this->artisan('dfe:status')
            ->expectsOutputToContain('[SEFAZ]')
            ->assertExitCode(1);
    }

    protected function statusResponse(string $cStat, string $xMotivo): string
    {
        $ret = '<retConsStatServCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<tpAmb>2</tpAmb><verAplic>SP_PL_CTe_400</verAplic><cStat>'.$cStat.'</cStat>'
            .'<xMotivo>'.$xMotivo.'</xMotivo><dhRecbto>2026-09-30T12:00:00-03:00</dhRecbto>'
            .'<tMed>1</tMed><cUF>35</cUF><tpImp>1</tpImp>'
            .'</retConsStatServCTe>';

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<cteStatusServicoCTResult xmlns="http://www.portalfiscal.inf.br/cte/wsdl/CTeStatusServicoV4">'
            .htmlspecialchars($ret, ENT_XML1 | ENT_COMPAT)
            .'</cteStatusServicoCTResult>'
            .'</soap:Body></soap:Envelope>';
    }
}
