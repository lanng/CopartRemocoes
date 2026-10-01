<?php

namespace Tests\Feature\Services\Dfe;

use App\Services\Dfe\SefazException;
use App\Services\Dfe\SefazResponse;
use App\Services\Dfe\SefazSoapClient;
use Illuminate\Support\Facades\Http;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class SefazSoapClientTest extends TestCase
{
    protected string $certPath;

    protected string $certPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $certificate = DfeTestCertificate::create();

        $this->certPath = $certificate['path'];
        $this->certPassword = $certificate['password'];

        config([
            'dfe.tp_amb' => '2',
            'dfe.base_url' => 'https://sefaz-hml.test/CTeWS/WS',
            'dfe.timeout' => 15,
            'dfe.cert_path' => $this->certPath,
            'dfe.cert_password' => $this->certPassword,
            'dfe.ca_bundle' => '/caminho/inexistente/sefaz-ca.pem',
            'dfe.log_channel' => 'null',
        ]);
    }

    public function test_status_sends_the_documented_envelope_and_parses_cstat_107(): void
    {
        Http::fake([
            'https://sefaz-hml.test/CTeWS/WS/CTeStatusServicoV4.asmx' => Http::response(
                $this->soapEnvelope('cteStatusServicoCTResult', $this->retConsStatServCTe()),
                200,
            ),
        ]);

        $response = app(SefazSoapClient::class)->status();

        $this->assertTrue($response->isServiceOnline());
        $this->assertSame('107', $response->cStat);
        $this->assertSame('Servico em Operacao', $response->xMotivo);
        $this->assertNull($response->protocol);

        Http::assertSent(function ($request): bool {
            $body = $request->body();

            return $request->url() === 'https://sefaz-hml.test/CTeWS/WS/CTeStatusServicoV4.asmx'
                && str_starts_with($body, '<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body>')
                && str_contains($body, '<cteDadosMsg xmlns="http://www.portalfiscal.inf.br/cte/wsdl/CTeStatusServicoV4">')
                && str_contains($body, '<consStatServCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte"><tpAmb>2</tpAmb><cUF>35</cUF><xServ>STATUS</xServ></consStatServCTe>')
                && ! str_contains($body, 'soap12:Header')
                && ! str_contains($body, 'Security')
                && str_contains($request->header('Content-Type')[0] ?? '', 'application/soap+xml');
        });
    }

    public function test_status_parses_a_response_with_the_ret_element_directly_embedded(): void
    {
        Http::fake([
            'https://sefaz-hml.test/CTeWS/WS/CTeStatusServicoV4.asmx' => Http::response(
                $this->soapEnvelope('cteStatusServicoCTResult', $this->retConsStatServCTe(), escaped: false),
                200,
            ),
        ]);

        $response = app(SefazSoapClient::class)->status();

        $this->assertSame('107', $response->cStat);
    }

    public function test_transmit_sends_gzip_base64_of_the_signed_xml_and_parses_the_protocol(): void
    {
        $signedXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<CTe xmlns="http://www.portalfiscal.inf.br/cte"><infCte Id="CTe35260912563112000130570010000000019997761234" versao="4.00"><ide><nCT>2962</nCT></ide></infCte></CTe>';

        Http::fake([
            'https://sefaz-hml.test/CTeWS/WS/CTeRecepcaoSincV4.asmx' => Http::response(
                $this->soapEnvelope('cteRetRecepcaoResult', $this->retCteAuthorized()),
                200,
            ),
        ]);

        $response = app(SefazSoapClient::class)->transmit($signedXml);

        $this->assertTrue($response->isAuthorized());
        $this->assertSame('100', $response->cStat);
        $this->assertSame('Autorizado o uso do CT-e', $response->xMotivo);
        $this->assertSame('135260007132708', $response->protocol);
        $this->assertSame('Ol5h4Q7LEbxFqervvg2/zUevfiI=', $response->digestValue);
        $this->assertSame('35260912563112000130570010000000019997761234', $response->accessKey);
        $this->assertSame('2026-09-30T12:00:00-03:00', $response->receivedAt);

        Http::assertSent(function ($request) use ($signedXml): bool {
            if (! str_contains($request->url(), 'CTeRecepcaoSincV4.asmx')) {
                return false;
            }

            preg_match('/<cteDadosMsg[^>]*>(.*)<\/cteDadosMsg>/s', $request->body(), $matches);

            $decoded = base64_decode($matches[1] ?? '', true);
            $this->assertIsString($decoded);

            $decompressed = gzdecode((string) $decoded);
            $this->assertIsString($decompressed);

            return $decompressed === $signedXml;
        });
    }

    public function test_server_error_throws_retryable_exception(): void
    {
        Http::fake([
            'https://sefaz-hml.test/CTeWS/WS/CTeStatusServicoV4.asmx' => Http::response('boom', 503),
        ]);

        try {
            app(SefazSoapClient::class)->status();
            $this->fail('Expected SefazException.');
        } catch (SefazException $exception) {
            $this->assertSame(503, $exception->httpStatus);
            $this->assertTrue($exception->retryable());
        }
    }

    public function test_connection_error_throws_retryable_exception(): void
    {
        Http::fake([
            '*' => Http::failedConnection(),
        ]);

        try {
            app(SefazSoapClient::class)->status();
            $this->fail('Expected SefazException.');
        } catch (SefazException $exception) {
            $this->assertSame(0, $exception->httpStatus);
            $this->assertTrue($exception->retryable());
        }
    }

    public function test_parses_a_soap_fault(): void
    {
        $fault = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<soap:Fault><soap:Code><soap:Value>soap:Sender</soap:Value></soap:Code>'
            .'<soap:Reason><soap:Text xml:lang="en">System.Web.Services.Protocols.SoapException: Fault</soap:Text></soap:Reason>'
            .'</soap:Fault>'
            .'</soap:Body></soap:Envelope>';

        $response = SefazResponse::fromSoapXml($fault);

        $this->assertTrue($response->isFault());
        $this->assertSame('soap:Sender', $response->faultCode);
        $this->assertStringContainsString('SoapException', (string) $response->xMotivo);
    }

    public function test_throws_when_the_response_has_no_ret_document(): void
    {
        $this->expectException(SefazException::class);
        $this->expectExceptionMessage('ret*');

        SefazResponse::fromSoapXml('<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><empty/></soap:Body></soap:Envelope>');
    }

    public function test_http_options_force_tls_1_2_http_1_1_and_p12_certificate(): void
    {
        $curl = $this->invokeHttpOptions()['curl'];

        $this->assertSame(CURL_SSLVERSION_TLSv1_2, $curl[CURLOPT_SSLVERSION]);
        $this->assertSame(CURL_HTTP_VERSION_1_1, $curl[CURLOPT_HTTP_VERSION]);
        $this->assertSame($this->certPath, $curl[CURLOPT_SSLCERT]);
        $this->assertSame('P12', $curl[CURLOPT_SSLCERTTYPE]);
        $this->assertSame($this->certPassword, $curl[CURLOPT_SSLCERTPASSWD]);
    }

    public function test_http_options_verify_peer_with_the_ca_bundle_when_provisioned(): void
    {
        $caBundle = tempnam(sys_get_temp_dir(), 'sefaz-ca');
        file_put_contents($caBundle, "-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----\n");
        config(['dfe.ca_bundle' => $caBundle]);

        $curl = $this->invokeHttpOptions()['curl'];

        $this->assertTrue($curl[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $curl[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame($caBundle, $curl[CURLOPT_CAINFO]);

        unlink($caBundle);
    }

    public function test_http_options_refuse_to_disable_peer_verification_in_production(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.ca_bundle' => '/caminho/inexistente/sefaz-ca.pem']);

        $this->expectException(SefazException::class);
        $this->expectExceptionMessage('DFE_CA_BUNDLE');

        $this->invokeHttpOptions();
    }

    /**
     * @return array<string, mixed>
     */
    protected function invokeHttpOptions(): array
    {
        $method = new \ReflectionMethod(SefazSoapClient::class, 'httpOptions');
        $method->setAccessible(true);

        return $method->invoke(app(SefazSoapClient::class));
    }

    protected function retConsStatServCTe(): string
    {
        return '<retConsStatServCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<tpAmb>2</tpAmb><verAplic>SP_PL_CTe_400</verAplic><cStat>107</cStat>'
            .'<xMotivo>Servico em Operacao</xMotivo><dhRecbto>2026-09-30T12:00:00-03:00</dhRecbto>'
            .'<tMed>1</tMed><cUF>35</cUF><tpImp>1</tpImp>'
            .'</retConsStatServCTe>';
    }

    protected function retCteAuthorized(): string
    {
        return '<retCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<protCTe versao="4.00">'
            .'<infProt>'
            .'<tpAmb>2</tpAmb><verAplic>SP_PL_CTe_400</verAplic><cStat>100</cStat>'
            .'<xMotivo>Autorizado o uso do CT-e</xMotivo><cUF>35</cUF>'
            .'<chCTe>35260912563112000130570010000000019997761234</chCTe>'
            .'<dhRecbto>2026-09-30T12:00:00-03:00</dhRecbto>'
            .'<nProt>135260007132708</nProt>'
            .'<digVal>Ol5h4Q7LEbxFqervvg2/zUevfiI=</digVal>'
            .'</infProt>'
            .'</protCTe></retCTe>';
    }

    protected function soapEnvelope(string $resultElement, string $innerXml, bool $escaped = true): string
    {
        // No formato escapado a SEFAZ embute o documento com a declaração XML;
        // no formato de elemento direto, o ret* entra sem a sua própria declaração.
        $content = $escaped
            ? htmlspecialchars('<?xml version="1.0" encoding="UTF-8"?>'.$innerXml, ENT_XML1 | ENT_COMPAT)
            : $innerXml;

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<'.$resultElement.' xmlns="http://www.portalfiscal.inf.br/cte/wsdl/CTeStatusServicoV4">'.$content.'</'.$resultElement.'>'
            .'</soap:Body></soap:Envelope>';
    }

    public function test_consult_sends_the_conssitcte_payload_and_parses_the_protocol(): void
    {
        $ret = '<retConsSitCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<tpAmb>2</tpAmb><verAplic>SP-CTe-2026-08-26-1</verAplic><cStat>100</cStat>'
            .'<xMotivo>Autorizado o uso do CT-e</xMotivo><cUF>35</cUF>'
            .'<protCTe versao="4.00"><infProt>'
            .'<tpAmb>2</tpAmb><verAplic>SP-CTe-2026-08-26-1</verAplic>'
            .'<chCTe>35260912563112000130570010000000019997761234</chCTe>'
            .'<dhRecbto>2026-10-01T12:00:00-03:00</dhRecbto>'
            .'<nProt>135260007132708</nProt>'
            .'<digVal>Ol5h4Q7LEbxFqervvg2/zUevfiI=</digVal>'
            .'<cStat>100</cStat><xMotivo>Autorizado o uso do CT-e</xMotivo>'
            .'</infProt></protCTe></retConsSitCTe>';

        Http::fake([
            'https://sefaz-hml.test/CTeWS/WS/CTeConsultaV4.asmx' => Http::response($this->soapEnvelope('cteConsultaCTeResult', $ret), 200),
        ]);

        $response = app(SefazSoapClient::class)->consult('35260912563112000130570010000000019997761234');

        $this->assertTrue($response->isAuthorized());
        $this->assertSame('135260007132708', $response->protocol);
        $this->assertSame('Ol5h4Q7LEbxFqervvg2/zUevfiI=', $response->digestValue);
        $this->assertSame('35260912563112000130570010000000019997761234', $response->accessKey);
        $this->assertStringContainsString('<retConsSitCTe', (string) $response->rawXml);

        Http::assertSent(function ($request): bool {
            $body = $request->body();

            return str_contains($request->url(), 'CTeConsultaV4.asmx')
                && str_contains($body, '<consSitCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">')
                && str_contains($body, '<xServ>CONSULTAR</xServ>')
                && str_contains($body, '<chCTe>35260912563112000130570010000000019997761234</chCTe>');
        });
    }
}
