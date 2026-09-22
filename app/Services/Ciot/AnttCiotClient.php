<?php

namespace App\Services\Ciot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnttCiotClient
{
    protected const CACHE_KEY = 'ciot:antt:token:%s';

    /**
     * Códigos de sucesso do DCS: 110 (dados inseridos) e 111 (consulta).
     *
     * @var list<string>
     */
    protected const SUCCESS_CODES = ['110', '111'];

    /**
     * Chaves de pagamento/segredo mascaradas nos logs.
     *
     * @var list<string>
     */
    protected const SENSITIVE_KEYS = ['numeroconta', 'numeroagencia', 'agencia', 'conta'];

    /**
     * Emite o IdOperacaoTransporte da próxima declaração. O ID NÃO é inventado
     * pelo cliente: o servidor ANTT controla a sequência global (spec §2.2).
     * Produção: gateway AWS próprio (POST /token com `chave`, depois /gerar com
     * Bearer — resposta em JSON minúsculo). Homologação: /gerar do appservices-hml
     * direto, apenas mTLS.
     *
     * @throws AnttCiotException
     */
    public function generateIdOperacaoTransporte(): string
    {
        $body = [
            'cpfCnpj' => (string) config('ciot.company.cnpj'),
            'cnpj' => (string) config('ciot.company.cnpj'),
        ];

        try {
            $generatorBase = config('ciot.gerar_base_url');

            if (filled($generatorBase)) {
                $token = $this->generatorToken((string) $generatorBase, $body);

                $response = Http::withOptions($this->httpOptions())
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer '.$token,
                    ])
                    ->timeout((int) config('ciot.timeout'))
                    ->post($generatorBase.'/gerar', $body);
            } else {
                $response = Http::withOptions($this->httpOptions())
                    ->withHeaders(['Accept' => 'application/json'])
                    ->timeout((int) config('ciot.timeout'))
                    ->post($this->url($this->path('simplified_generate')), $body);
            }
        } catch (ConnectionException $exception) {
            $this->log('error', 'Falha de conexão ao gerar IdOperacaoTransporte na ANTT.', ['error' => $exception->getMessage()]);

            throw new AnttCiotException('Falha de conexão com a ANTT: '.$exception->getMessage(), httpStatus: 0);
        }

        if ($response->failed()) {
            $this->log('error', 'Geração de IdOperacaoTransporte rejeitada.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new AnttCiotException(
                sprintf('Geração de IdOperacaoTransporte falhou (HTTP %d): %s', $response->status(), $response->body()),
                httpStatus: $response->status(),
            );
        }

        $idOperacao = (string) ($response->json('dados.ciot') ?? $response->json('Dados.CIOT') ?? '');

        if ($idOperacao === '') {
            throw new AnttCiotException('Resposta do gerador sem o campo "ciot".', body: (array) $response->json());
        }

        $this->log('info', 'IdOperacaoTransporte emitido pela ANTT.', ['tamanho' => strlen($idOperacao)]);

        return $idOperacao;
    }

    /**
     * Token do gerador de produção (gateway AWS): POST /token com o header
     * `chave` e o corpo cpfCnpj/cnpj (spec §2.2).
     *
     * @param  array<string, string>  $body
     *
     * @throws AnttCiotException
     */
    protected function generatorToken(string $generatorBase, array $body): string
    {
        $response = Http::withOptions($this->httpOptions())
            ->withHeaders([
                'Accept' => 'application/json',
                'chave' => (string) config('ciot.api_key'),
            ])
            ->timeout((int) config('ciot.timeout'))
            ->post($generatorBase.'/token', $body);

        if ($response->failed()) {
            $this->log('error', 'Token do gerador de produção rejeitado.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new AnttCiotException(
                sprintf('Token do gerador de ID falhou (HTTP %d): %s', $response->status(), $response->body()),
                httpStatus: $response->status(),
            );
        }

        $token = (string) ($response->json('token') ?? '');

        if ($token === '') {
            throw new AnttCiotException('Resposta de token do gerador sem campo "token".', body: (array) $response->json());
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function declare(array $payload): AnttCiotResponse
    {
        return $this->businessPost($this->path('declare'), $payload);
    }

    public function cancel(string $ciot, string $motivo): AnttCiotResponse
    {
        return $this->businessPost($this->path('cancel'), [
            'CodigoIdentificacaoOperacao' => $ciot,
            'MotivoCancelamento' => $motivo,
        ]);
    }

    public function encerrar(string $ciot, ?float $pesoCarga = null): AnttCiotResponse
    {
        $payload = ['CodigoIdentificacaoOperacao' => $ciot];

        // O encerramento de lotação exige o peso aninhado em
        // DadosCarga.PesoTotalCarga (na raiz o campo não liga — spec §2.2).
        if ($pesoCarga !== null) {
            $payload['DadosCarga'] = ['PesoTotalCarga' => $pesoCarga];
        }

        return $this->businessPost($this->path('close'), $payload);
    }

    /**
     * Geração simplificada (wrapper oficial da DLL) — uso diagnóstico.
     */
    public function simplifiedGenerate(string $cpfCnpj): AnttCiotResponse
    {
        try {
            $response = Http::withOptions($this->httpOptions())
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout((int) config('ciot.timeout'))
                ->post($this->url($this->path('simplified_generate')), ['cpfCnpj' => $cpfCnpj]);
        } catch (ConnectionException $exception) {
            throw new AnttCiotException('Falha de conexão com a ANTT: '.$exception->getMessage(), httpStatus: 0);
        }

        if ($response->serverError()) {
            throw new AnttCiotException(
                sprintf('Erro de servidor ANTT (HTTP %d): %s', $response->status(), $response->body()),
                httpStatus: $response->status(),
                body: (array) $response->json(),
            );
        }

        return new AnttCiotResponse($response->status(), (array) $response->json());
    }

    /**
     * Rotas canônicas `/api/*`: mTLS basta (spec §2.1); se a ANTT devolver 401,
     * refaz com Bearer (camada de token usada por Instituições de Pagamento).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function businessPost(string $path, array $payload): AnttCiotResponse
    {
        try {
            $response = $this->post($path, $payload, null);

            if ($response->status() === 401) {
                $response = $this->post($path, $payload, $this->authenticate());
            }
        } catch (ConnectionException $exception) {
            $this->log('error', 'Falha de conexão com a ANTT.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            throw new AnttCiotException('Falha de conexão com a ANTT: '.$exception->getMessage(), httpStatus: 0);
        }

        if ($response->serverError()) {
            throw new AnttCiotException(
                sprintf('Erro de servidor ANTT (HTTP %d): %s', $response->status(), $response->body()),
                httpStatus: $response->status(),
                body: (array) $response->json(),
            );
        }

        return new AnttCiotResponse($response->status(), (array) $response->json());
    }

    public function authenticate(bool $force = false): string
    {
        $cacheKey = $this->tokenCacheKey();

        if (! $force) {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $apiKey = config('ciot.api_key');

        if (empty($apiKey)) {
            throw new AnttCiotException('CIOT_API_KEY (header "chave") não configurada.');
        }

        try {
            $response = Http::withOptions($this->httpOptions())
                ->withHeaders([
                    'Accept' => 'application/json',
                    'chave' => $apiKey,
                ])
                ->timeout((int) config('ciot.timeout'))
                ->post($this->url('token'), []);
        } catch (ConnectionException $exception) {
            $this->log('error', 'Falha de conexão ao autenticar na ANTT.', ['error' => $exception->getMessage()]);

            throw new AnttCiotException('Falha de conexão com a ANTT: '.$exception->getMessage(), httpStatus: 0);
        }

        if ($response->failed()) {
            $this->log('error', 'Autenticação ANTT rejeitada.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new AnttCiotException(
                sprintf('Autenticação ANTT falhou (HTTP %d): %s', $response->status(), $response->body()),
                httpStatus: $response->status(),
            );
        }

        $token = (string) ($response->json('token') ?? '');

        if ($token === '') {
            throw new AnttCiotException('Resposta de token da ANTT sem campo "token".', body: (array) $response->json());
        }

        Cache::put($cacheKey, $token, now()->addMinutes((int) config('ciot.token_ttl_minutes')));

        $this->log('info', 'Token ANTT obtido.', ['token_length' => strlen($token)]);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(string $path, array $payload, ?string $token): Response
    {
        $headers = ['Accept' => 'application/json'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $this->log('info', 'Enviando requisição à ANTT.', [
            'path' => $path,
            'payload' => $this->maskPayload($payload),
        ]);

        return Http::withOptions($this->httpOptions())
            ->withHeaders($headers)
            ->timeout((int) config('ciot.timeout'))
            ->post($this->url($path), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function httpOptions(): array
    {
        // O gateway de produção exige o certificado cliente em renegociação TLS —
        // impossível em TLS 1.3 (negociado por padrão), o que faz o cURL nunca
        // entregá-lo (401 CERTIFICADO_NAO_FORNECIDO). TLS 1.2 é obrigatório (spec §2.2).
        $options = [
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];

        $certPath = config('ciot.cert_path');

        if (filled($certPath)) {
            $extension = strtolower(pathinfo((string) $certPath, PATHINFO_EXTENSION));

            if (in_array($extension, ['pfx', 'p12'], true)) {
                $options['curl'] = [
                    CURLOPT_SSLCERT => $certPath,
                    CURLOPT_SSLCERTTYPE => 'P12',
                    CURLOPT_SSLCERTPASSWD => (string) config('ciot.cert_password'),
                    CURLOPT_SSLKEYPASSWD => (string) config('ciot.cert_password'),
                ];
            } else {
                $options['cert'] = $certPath;
                $options['ssl_key'] = [$certPath, (string) config('ciot.cert_password')];
            }
        }

        return $options;
    }

    protected function path(string $key): string
    {
        return (string) config("ciot.paths.{$key}");
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('ciot.base_url'), '/').'/'.ltrim($path, '/');
    }

    protected function tokenCacheKey(): string
    {
        return sprintf(self::CACHE_KEY, (string) config('ciot.env'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function maskPayload(array $payload): array
    {
        $masked = json_decode($this->maskString(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}'), true);

        return is_array($masked) ? $masked : [];
    }

    protected function maskString(string $json): string
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            $json = preg_replace(
                '/("'.preg_quote($key, '/').'"\s*:\s*")(?:[^"]*)(")/i',
                '$1***$2',
                $json,
            ) ?? $json;
        }

        return (string) preg_replace('/("Bearer )([^"]*)(")/i', '$1***$3', $json);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::channel((string) config('ciot.log_channel'))->{$level}($message, $context);
    }
}
