<?php

namespace App\Services\Dfe;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MdfeSoapClient
{
    public function __construct(private readonly ?SefazCertificate $certificate = null) {}

    public function transmit(string $signedXml): SefazResponse
    {
        return $this->send('recepcao_sinc', 'MDFeRecepcaoSinc', base64_encode((string) gzencode($signedXml)));
    }

    public function consult(string $accessKey): SefazResponse
    {
        $payload = '<consSitMDFe versao="'.e((string) config('dfe.mdfe.version')).'" xmlns="http://www.portalfiscal.inf.br/mdfe">'
            .'<tpAmb>'.(string) config('dfe.tp_amb').'</tpAmb><xServ>CONSULTAR</xServ>'
            .'<chMDFe>'.e($accessKey).'</chMDFe></consSitMDFe>';

        return $this->send('consulta', 'MDFeConsulta', $payload);
    }

    public function transmitEvent(string $signedEventXml, ?callable $onRawResponse = null): SefazResponse
    {
        $payloadXml = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $signedEventXml, 1) ?? $signedEventXml;
        $contentType = 'application/soap+xml; charset=utf-8; action="http://www.portalfiscal.inf.br/mdfe/wsdl/MDFeRecepcaoEvento/mdfeRecepcaoEvento"';

        return $this->send('evento', 'MDFeRecepcaoEvento', $payloadXml, $contentType, $onRawResponse);
    }

    protected function send(
        string $serviceKey,
        string $serviceName,
        string $payloadXml,
        string $contentType = 'application/soap+xml; charset=utf-8',
        ?callable $onRawResponse = null,
    ): SefazResponse {
        $url = rtrim((string) config('dfe.mdfe.base_url'), '/').(string) config("dfe.mdfe.paths.{$serviceKey}");

        try {
            $response = Http::withOptions($this->httpOptions())
                ->withBody($this->envelope($serviceName, $payloadXml), $contentType)
                ->timeout((int) config('dfe.timeout'))
                ->post($url);
        } catch (ConnectionException $exception) {
            throw new SefazException('Falha de conexão com o serviço MDF-e: '.$exception->getMessage(), httpStatus: 0);
        }

        if ($onRawResponse !== null) {
            $onRawResponse($response->status(), $response->header('Content-Type'), $response->body());
        }

        if ($response->serverError()) {
            throw new SefazException(
                sprintf('Erro de servidor MDF-e (HTTP %d): %s', $response->status(), $response->body()),
                httpStatus: $response->status(),
            );
        }

        $result = SefazResponse::fromSoapXml($response->body(), $response->status());
        Log::channel((string) config('dfe.log_channel'))->info('Resposta do serviço MDF-e.', [
            'service' => $serviceName,
            'tp_amb' => (string) config('dfe.tp_amb'),
            'cStat' => $result->cStat,
            'xMotivo' => $result->xMotivo,
        ]);

        return $result;
    }

    protected function envelope(string $serviceName, string $payloadXml): string
    {
        return '<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body>'
            .'<mdfeDadosMsg xmlns="http://www.portalfiscal.inf.br/mdfe/wsdl/'.$serviceName.'">'.$payloadXml.'</mdfeDadosMsg>'
            .'</soap12:Body></soap12:Envelope>';
    }

    /** @return array<string, mixed> */
    protected function httpOptions(): array
    {
        $certificate = $this->certificate ?? new SefazCertificate;
        $curl = [
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $caBundle = (string) config('dfe.ca_bundle');

        if (is_readable($caBundle)) {
            $curl[CURLOPT_CAINFO] = $caBundle;
        } elseif ((string) config('dfe.tp_amb') === '1') {
            throw new SefazException('Cadeia CA não encontrada; verificação TLS é obrigatória para MDF-e.');
        } else {
            $curl[CURLOPT_SSL_VERIFYPEER] = false;
            $curl[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        $curl[CURLOPT_SSLCERT] = $certificate->certificatePath();
        $curl[CURLOPT_SSLCERTTYPE] = 'P12';
        $curl[CURLOPT_SSLCERTPASSWD] = $certificate->password();
        $curl[CURLOPT_SSLKEYPASSWD] = $certificate->password();

        return ['curl' => $curl];
    }
}
