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
    public function declare(array $payload): AnttCiotResponse
    {
        return $this->authenticatedPost($this->path('declare'), $payload);
    }

    public function cancel(string $ciot, string $motivo): AnttCiotResponse
    {
        return $this->authenticatedPost($this->path('cancel'), [
            'CodigoIdentificacaoOperacao' => $ciot,
            'MotivoCancelamento' => $motivo,
        ]);
    }

    public function encerrar(string $ciot): AnttCiotResponse
    {
        return $this->authenticatedPost($this->path('close'), [
            'CodigoIdentificacaoOperacao' => $ciot,
        ]);
    }

    /**
     * Geração simplificada (wrapper oficial da DLL, `/gerar`) — uso diagnóstico;
     * a emissão canônica vai por declare().
     */
    public function simplifiedGenerate(string $cpfCnpj): AnttCiotResponse
    {
        return $this->authenticatedPost($this->path('simplified_generate'), [
            'cpfCnpj' => $cpfCnpj,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function authenticatedPost(string $path, array $payload): AnttCiotResponse
    {
        try {
            $response = $this->postWithToken($path, $payload, $this->authenticate());

            if (in_array($response->status(), [401, 403], true)) {
                $response = $this->postWithToken($path, $payload, $this->authenticate(force: true));
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

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postWithToken(string $path, array $payload, string $token): Response
    {
        $this->log('info', 'Enviando requisição à ANTT.', [
            'path' => $path,
            'payload' => $this->maskPayload($payload),
        ]);

        return Http::withOptions($this->httpOptions())
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ])
            ->timeout((int) config('ciot.timeout'))
            ->post($this->url($path), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function httpOptions(): array
    {
        $options = [];

        $certPath = config('ciot.cert_path');

        if (filled($certPath)) {
            $extension = strtolower(pathinfo((string) $certPath, PATHINFO_EXTENSION));

            if (in_array($extension, ['pfx', 'p12'], true)) {
                $options['curl'] = [
                    CURLOPT_SSLCERT => $certPath,
                    CURLOPT_SSLCERTTYPE => 'P12',
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
