<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Respostas SOAP da SEFAZ para os testes de F3, com o digVal ecoado a partir
 * do DigestValue realmente presente no XML transmitido (cross-check real).
 */
class DfeSoapResponses
{
    public static function fakeOnline(string $baseUrl): void
    {
        Http::fake([
            $baseUrl.'/CTeStatusServicoV4.asmx' => Http::response(self::statusEnvelope('107', 'Servico em Operacao'), 200),
        ]);
    }

    public static function fakeTransmission(string $baseUrl, ?string $forcedDigVal = null, string $cStat = '100', string $xMotivo = 'Autorizado o uso do CT-e', string $protocol = '135260007132708'): void
    {
        Http::fake([
            $baseUrl.'/CTeRecepcaoSincV4.asmx' => function ($request) use ($forcedDigVal, $cStat, $xMotivo, $protocol) {
                return Http::response(self::retCteEnvelope(
                    self::transmittedXml($request->body()),
                    $forcedDigVal,
                    $cStat,
                    $xMotivo,
                    $protocol,
                ), 200);
            },
        ]);
    }

    /**
     * Recupera o XML assinado de dentro do envelope transmitido.
     */
    public static function transmittedXml(string $requestBody): string
    {
        preg_match('/<cteDadosMsg[^>]*>(.*)<\/cteDadosMsg>/s', $requestBody, $matches);

        return (string) gzdecode((string) base64_decode($matches[1] ?? '', true));
    }

    public static function statusEnvelope(string $cStat, string $xMotivo): string
    {
        $ret = '<retConsStatServCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<tpAmb>2</tpAmb><verAplic>SP-CTe-2026-08-26-1</verAplic><cStat>'.$cStat.'</cStat>'
            .'<xMotivo>'.$xMotivo.'</xMotivo><cUF>35</cUF>'
            .'<dhRecbto>2026-10-01T12:00:00-03:00</dhRecbto>'
            .'</retConsStatServCTe>';

        return self::envelope('cteStatusServicoCTResult', $ret);
    }

    public static function retCteEnvelope(string $signedXml, ?string $forcedDigVal, string $cStat, string $xMotivo, string $protocol): string
    {
        $accessKey = '';
        $digest = $forcedDigVal;

        if ($forcedDigVal === null) {
            preg_match('/<infCte Id="CTe(\d{44})"/', $signedXml, $keyMatches);
            $accessKey = $keyMatches[1] ?? str_repeat('3', 44);

            preg_match('/<DigestValue>([^<]*)<\/DigestValue>/', $signedXml, $digestMatches);
            $digest = $digestMatches[1] ?? base64_encode(sha1($signedXml, true));
        }

        $ret = '<retCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<protCTe versao="4.00">'
            // Ordem xs:sequence do infProt (gabarito 2962): tpAmb, verAplic,
            // chCTe, dhRecbto, nProt, digVal, cStat, xMotivo.
            .'<infProt>'
            .'<tpAmb>2</tpAmb><verAplic>SP-CTe-2026-08-26-1</verAplic>'
            .'<chCTe>'.$accessKey.'</chCTe>'
            .'<dhRecbto>2026-10-01T12:00:00-03:00</dhRecbto>'
            .'<nProt>'.$protocol.'</nProt>'
            .'<digVal>'.$digest.'</digVal>'
            .'<cStat>'.$cStat.'</cStat>'
            .'<xMotivo>'.$xMotivo.'</xMotivo>'
            .'</infProt>'
            .'</protCTe></retCTe>';

        return self::envelope('cteRetRecepcaoResult', $ret);
    }

    protected static function envelope(string $resultElement, string $retXml): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<'.$resultElement.' xmlns="http://www.portalfiscal.inf.br/cte/wsdl/CTeStatusServicoV4">'
            .htmlspecialchars('<?xml version="1.0" encoding="UTF-8"?>'.$retXml, ENT_XML1 | ENT_COMPAT)
            .'</'.$resultElement.'>'
            .'</soap:Body></soap:Envelope>';
    }
}
