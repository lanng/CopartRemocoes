<?php

namespace Tests\Feature\Services\Dfe;

use App\Services\Dfe\MdfeSoapClient;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\SefazResponse;
use DOMDocument;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class MdfeSoapClientTest extends TestCase
{
    public function test_transmits_gzipped_signed_xml_to_the_official_homologation_service(): void
    {
        config([
            'dfe.tp_amb' => '2',
            'dfe.mdfe.base_url' => 'https://mdfe-homologacao.svrs.rs.gov.br',
            'dfe.mdfe.paths.recepcao_sinc' => '/ws/MDFeRecepcaoSinc/MDFeRecepcaoSinc.asmx',
        ]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('<retMDFeRecepcaoSinc><cStat>104</cStat><xMotivo>Lote processado</xMotivo></retMDFeRecepcaoSinc>')]);
        $certificate = DfeTestCertificate::create();

        $response = (new MdfeSoapClient(new SefazCertificate($certificate['path'], $certificate['password'])))->transmit('<MDFe>teste</MDFe>');

        $this->assertSame('104', $response->cStat);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame(
                'https://mdfe-homologacao.svrs.rs.gov.br/ws/MDFeRecepcaoSinc/MDFeRecepcaoSinc.asmx',
                $request->url(),
            );
            $this->assertStringContainsString('/wsdl/MDFeRecepcaoSinc', $request->body());
            preg_match('/<mdfeDadosMsg[^>]*>([^<]+)<\/mdfeDadosMsg>/', $request->body(), $matches);

            return isset($matches[1]) && gzdecode((string) base64_decode($matches[1], true)) === '<MDFe>teste</MDFe>';
        });
    }

    public function test_transmits_signed_event_xml_without_compression_or_base64(): void
    {
        config([
            'dfe.tp_amb' => '2',
            'dfe.mdfe.base_url' => 'https://mdfe-homologacao.svrs.rs.gov.br',
            'dfe.mdfe.paths.evento' => '/ws/MDFeRecepcaoEvento/MDFeRecepcaoEvento.asmx',
        ]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('<retEventoMDFe><infEvento><cStat>135</cStat><xMotivo>Evento registrado</xMotivo></infEvento></retEventoMDFe>')]);
        $certificate = DfeTestCertificate::create();
        $event = '<?xml version="1.0" encoding="UTF-8"?><eventoMDFe versao="3.00"><infEvento Id="ID110112"/></eventoMDFe>';

        $response = (new MdfeSoapClient(new SefazCertificate($certificate['path'], $certificate['password'])))->transmitEvent($event);

        $this->assertSame('135', $response->cStat);
        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('/wsdl/MDFeRecepcaoEvento', $request->body());
            $this->assertSame(
                'application/soap+xml; charset=utf-8; action="http://www.portalfiscal.inf.br/mdfe/wsdl/MDFeRecepcaoEvento/mdfeRecepcaoEvento"',
                $request->header('Content-Type')[0],
            );
            $document = new DOMDocument;
            $this->assertTrue($document->loadXML($request->body()));

            return str_contains($request->body(), '<eventoMDFe versao="3.00">');
        });
    }

    public function test_consultation_uses_root_status_instead_of_authorization_protocol_status(): void
    {
        $response = SefazResponse::fromSoapXml('<retConsSitMDFe><cStat>132</cStat><xMotivo>Encerrado</xMotivo>'
            .'<protMDFe><infProt><cStat>100</cStat><xMotivo>Autorizado</xMotivo><nProt>935260000071663</nProt></infProt></protMDFe>'
            .'</retConsSitMDFe>');

        $this->assertSame('132', $response->cStat);
        $this->assertSame('Encerrado', $response->xMotivo);
        $this->assertSame('935260000071663', $response->protocol);
        $this->assertFalse($response->hasMdfeClosureEvent);

        $withEvent = SefazResponse::fromSoapXml('<retConsSitMDFe><cStat>100</cStat><xMotivo>Autorizado</xMotivo>'
            .'<procEventoMDFe><eventoMDFe><infEvento><tpEvento>110112</tpEvento></infEvento></eventoMDFe>'
            .'<retEventoMDFe/></procEventoMDFe></retConsSitMDFe>');

        $this->assertTrue($withEvent->hasMdfeClosureEvent);

        $cancelled = SefazResponse::fromSoapXml('<retConsSitMDFe><cStat>101</cStat><xMotivo>Cancelamento homologado</xMotivo>'
            .'<protMDFe><infProt><cStat>100</cStat><nProt>935260000000021</nProt></infProt></protMDFe>'
            .'<procEventoMDFe><eventoMDFe><infEvento Id="ID110111'.str_repeat('2', 44).'01">'
            .'<tpEvento>110111</tpEvento><nSeqEvento>1</nSeqEvento><detEvento versaoEvento="3.00">'
            .'<evCancMDFe><nProt>935260000000021</nProt><xJust>Cancelamento solicitado pelo operador.</xJust>'
            .'</evCancMDFe></detEvento></infEvento></eventoMDFe>'
            .'<retEventoMDFe><infEvento><cStat>135</cStat><nProt>935260000000099</nProt>'
            .'<dhRegEvento>2026-10-07T14:45:00-03:00</dhRegEvento></infEvento></retEventoMDFe>'
            .'</procEventoMDFe></retConsSitMDFe>');

        $this->assertSame('101', $cancelled->cStat);
        $this->assertSame('935260000000021', $cancelled->protocol);
        $this->assertFalse($cancelled->hasMdfeClosureEvent);
        $this->assertTrue($cancelled->hasMdfeCancellationEvent);
        $this->assertSame('935260000000099', $cancelled->cancellationEventProtocol);
        $this->assertSame('2026-10-07T14:45:00-03:00', $cancelled->cancellationEventReceivedAt);
    }

    public function test_parses_closed_response_metadata_without_treating_it_as_cancellation(): void
    {
        $response = SefazResponse::fromSoapXml(
            '<retEventoMDFe><infEvento><cStat>609</cStat>'
            .'<xMotivo>MDF-e já encerrado [nProt:935260000000088][dhEnc:2026-10-07T14:45:00-03:00]</xMotivo>'
            .'</infEvento></retEventoMDFe>',
        );

        $this->assertSame('609', $response->cStat);
        $this->assertSame('935260000000088', $response->closureEventProtocol);
        $this->assertSame('2026-10-07T14:45:00-03:00', $response->closureEventReceivedAt);
        $this->assertNull($response->hasMdfeCancellationEvent);
    }
}
