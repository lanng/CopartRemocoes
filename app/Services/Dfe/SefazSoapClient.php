<?php

namespace App\Services\Dfe;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente SOAP 1.2 dos serviços CTe 4.00 da SEFAZ/SP (spec §2.3): envelope
 * apenas com Body (WS-Security extinto — sem Header), mTLS com o A1 do CIOT,
 * TLS 1.2 + HTTP/1.1 forçados (lição já aplicada no AnttCiotClient).
 */
class SefazSoapClient
{
    public function __construct(protected ?SefazCertificate $certificate = null)
    {
        $this->certificate ??= new SefazCertificate;
    }

    /**
     * StatusServico (consStatServCTe) — serviço online = cStat 107 (spec §2.3).
     */
    public function status(): SefazResponse
    {
        $payload = '<consStatServCTe versao="'.$this->version().'" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<tpAmb>'.$this->tpAmb().'</tpAmb>'
            .'<cUF>'.(int) config('dfe.cuf').'</cUF>'
            .'<xServ>STATUS</xServ>'
            .'</consStatServCTe>';

        return $this->send('status', $payload);
    }

    /**
     * Recepção síncrona (CTeRecepcaoSincV4): payload = GZip + Base64 do XML
     * assinado inteiro; resposta retCTe/protCTe/infProt (cStat/nProt/digVal).
     */
    public function transmit(string $signedXml): SefazResponse
    {
        return $this->send('recepcao_sinc', base64_encode((string) gzencode($signedXml)));
    }

    public function transmitEvent(string $signedEventXml, ?callable $onRawResponse = null): SefazResponse
    {
        $payloadXml = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $signedEventXml, 1) ?? $signedEventXml;

        return $this->send('evento', $payloadXml, $onRawResponse);
    }

    /**
     * Consulta de situação (CTeConsultaV4): usada pelo guard de retransmissão
     * — antes de queimar um novo número, verifica se a chave da tentativa
     * anterior já foi autorizada (spec dfe F4).
     */
    public function consult(string $accessKey): SefazResponse
    {
        $payload = '<consSitCTe versao="'.$this->version().'" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<tpAmb>'.$this->tpAmb().'</tpAmb>'
            .'<xServ>CONSULTAR</xServ>'
            .'<chCTe>'.$accessKey.'</chCTe>'
            .'</consSitCTe>';

        return $this->send('consulta', $payload);
    }

    protected function send(string $serviceKey, string $payloadXml, ?callable $onRawResponse = null): SefazResponse
    {
        $service = (string) config("dfe.paths.{$serviceKey}");
        $serviceName = (string) preg_replace('/\.asmx$/', '', $service);
        $url = rtrim((string) config('dfe.base_url'), '/').'/'.$service;

        $this->log('info', 'Enviando requisição à SEFAZ.', [
            'service' => $serviceName,
            'tp_amb' => $this->tpAmb(),
        ]);

        try {
            $response = Http::withOptions($this->httpOptions())
                ->withBody($this->envelope($serviceName, $payloadXml), 'application/soap+xml; charset=utf-8')
                ->timeout((int) config('dfe.timeout'))
                ->post($url);
        } catch (ConnectionException $exception) {
            $this->log('error', 'Falha de conexão com a SEFAZ.', [
                'service' => $serviceName,
                'error' => $exception->getMessage(),
            ]);

            throw new SefazException('Falha de conexão com a SEFAZ: '.$exception->getMessage(), httpStatus: 0);
        }

        if ($onRawResponse !== null) {
            $onRawResponse($response->status(), $response->header('Content-Type'), $response->body());
        }

        if ($response->serverError()) {
            $this->log('error', 'Erro de servidor da SEFAZ.', [
                'service' => $serviceName,
                'status' => $response->status(),
            ]);

            throw new SefazException(
                sprintf('Erro de servidor SEFAZ (HTTP %d): %s', $response->status(), $response->body()),
                httpStatus: $response->status(),
            );
        }

        $sefazResponse = SefazResponse::fromSoapXml($response->body(), $response->status());

        $this->log('info', 'Resposta da SEFAZ.', [
            'service' => $serviceName,
            'cStat' => $sefazResponse->cStat,
            'xMotivo' => $sefazResponse->xMotivo,
            'fault' => $sefazResponse->faultCode,
        ]);

        return $sefazResponse;
    }

    /**
     * Envelope da receita provada (spec §2.3): SOAP 1.2, apenas Body, o payload
     * dentro de cteDadosMsg com o namespace do WSDL do serviço.
     */
    protected function envelope(string $serviceName, string $payloadXml): string
    {
        return '<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body>'
            .'<cteDadosMsg xmlns="http://www.portalfiscal.inf.br/cte/wsdl/'.$serviceName.'">'.$payloadXml.'</cteDadosMsg>'
            .'</soap12:Body></soap12:Envelope>';
    }

    /**
     * @return array<string, mixed>
     */
    protected function httpOptions(): array
    {
        // Todas as opções de TLS vão no sub-array 'curl': o Guzzle só aplica
        // CURLOPT_* a partir dele (opções inteiras de nível superior são
        // ignoradas). TLS 1.2 + HTTP/1.1 forçados como no AnttCiotClient (spec §2.3).
        $curl = [
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];

        $caBundle = (string) config('dfe.ca_bundle');

        if ($caBundle !== '' && is_readable($caBundle)) {
            // Verificação de peer obrigatória com a cadeia CA da SEFAZ embutida
            // (spec §7) — VERIFYPEER=false era artefato de spike.
            $curl[CURLOPT_SSL_VERIFYPEER] = true;
            $curl[CURLOPT_SSL_VERIFYHOST] = 2;
            $curl[CURLOPT_CAINFO] = $caBundle;
        } else {
            if ($this->tpAmb() === 1) {
                throw new SefazException('Cadeia CA da SEFAZ não encontrada (DFE_CA_BUNDLE): verificação de peer é obrigatória em produção (spec §7).');
            }

            $this->log('warning', 'DFE_CA_BUNDLE inexistente — verificação de TLS desativada (aceitável apenas em homologação).');

            $curl[CURLOPT_SSL_VERIFYPEER] = false;
            $curl[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        // Mesmo A1 do CIOT, P12 direto (spec §7).
        $curl[CURLOPT_SSLCERT] = $this->certificate->certificatePath();
        $curl[CURLOPT_SSLCERTTYPE] = 'P12';
        $curl[CURLOPT_SSLCERTPASSWD] = $this->certificate->password();
        $curl[CURLOPT_SSLKEYPASSWD] = $this->certificate->password();

        return ['curl' => $curl];
    }

    protected function tpAmb(): int
    {
        return (int) config('dfe.tp_amb', '2');
    }

    protected function version(): string
    {
        return (string) config('dfe.cte_version', '4.00');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::channel((string) config('dfe.log_channel'))->{$level}($message, $context);
    }
}
