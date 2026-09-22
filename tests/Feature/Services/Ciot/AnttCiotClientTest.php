<?php

namespace Tests\Feature\Services\Ciot;

use App\Services\Ciot\AnttCiotClient;
use App\Services\Ciot\AnttCiotException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnttCiotClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.env' => 'homologacao',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.api_key' => 'test-api-key',
            'ciot.timeout' => 15,
        ]);

        Cache::forget('ciot:antt:token:homologacao');
    }

    public function test_authenticate_posts_chave_header_and_caches_the_token(): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'abc123'], 200),
        ]);

        $client = app(AnttCiotClient::class);

        $this->assertSame('abc123', $client->authenticate());
        $this->assertSame('abc123', Cache::get('ciot:antt:token:homologacao'));

        $client->authenticate();

        Http::assertSentCount(1);

        Http::assertSent(function ($request): bool {
            $body = $request->body();

            return $request->url() === 'https://antt-hml.test/pefServices/token'
                && $request->hasHeader('chave', 'test-api-key')
                && $request->hasHeader('Accept', 'application/json')
                && in_array($body, ['', '{}', '[]'], true);
        });
    }

    public function test_authenticate_throws_when_api_key_is_missing(): void
    {
        config(['ciot.api_key' => null]);

        $this->expectException(AnttCiotException::class);
        $this->expectExceptionMessage('CIOT_API_KEY');

        app(AnttCiotClient::class)->authenticate();
    }

    public function test_authenticate_throws_on_rejection(): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['Message' => 'Acesso Negado'], 401),
        ]);

        try {
            app(AnttCiotClient::class)->authenticate();
            $this->fail('Expected AnttCiotException.');
        } catch (AnttCiotException $exception) {
            $this->assertSame(401, $exception->httpStatus);
            $this->assertStringContainsString('Acesso Negado', $exception->getMessage());
        }
    }

    public function test_declare_parses_the_homologacao_success_envelope(): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Sucesso' => true,
                'Mensagem' => 'CIOT gerado com sucesso',
                'Dados' => ['CIOT' => '560000563230', 'CpfCnpj' => '12.563.112/0001-30', 'DataGeracao' => '2026-09-21T19:15:20'],
                'Erros' => null,
            ], 200),
        ]);

        $response = app(AnttCiotClient::class)->declare(['cpfCnpj' => '12563112000130']);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->sucesso());
        $this->assertSame('CIOT gerado com sucesso', $response->mensagem());
        $this->assertSame('560000563230', $response->ciotNumber());
        $this->assertSame('560000563230', $response->identificacaoOperacao());
        $this->assertNull($response->protocolo());
    }

    public function test_declare_posts_without_bearer_and_parses_response(): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'dados' => ['ciot' => '520031583158'],
            ], 200),
        ]);

        $response = app(AnttCiotClient::class)->declare(['IdOperacaoTransporte' => '260921123456']);

        $this->assertSame('520031583158', $response->ciotNumber());
        $this->assertSame('520031583158', $response->identificacaoOperacao());

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte'
                && ! $request->hasHeader('Authorization')
                && $request->data()['IdOperacaoTransporte'] === '260921123456';
        });
    }

    public function test_generate_id_operacao_transporte_uses_the_aws_gateway_in_production(): void
    {
        config([
            'ciot.company.cnpj' => '12563112000130',
            'ciot.gerar_base_url' => 'https://gateway.test/api-ciot-prd/GeradorCIOT',
        ]);

        Http::fake([
            'https://gateway.test/api-ciot-prd/GeradorCIOT/token' => Http::response(['token' => 'jwt-token'], 200),
            'https://gateway.test/api-ciot-prd/GeradorCIOT/gerar' => Http::response([
                'sucesso' => true,
                'dados' => ['ciot' => '520032942169', 'cnpj' => '12.563.112/0001-30'],
            ], 200),
        ]);

        $idOperacao = app(AnttCiotClient::class)->generateIdOperacaoTransporte();

        $this->assertSame('520032942169', $idOperacao);

        Http::assertSent(function ($request): bool {
            if (str_contains($request->url(), '/token')) {
                return $request->hasHeader('chave', 'test-api-key')
                    && $request->data() === ['cpfCnpj' => '12563112000130', 'cnpj' => '12563112000130'];
            }

            return str_contains($request->url(), '/gerar')
                && $request->hasHeader('Authorization', 'Bearer jwt-token');
        });
    }

    public function test_generate_id_operacao_transporte_posts_both_keys_and_returns_dados_ciot(): void
    {
        config(['ciot.company.cnpj' => '12563112000130']);

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '560000563274', 'CpfCnpj' => '12.563.112/0001-30'],
            ], 200),
        ]);

        $idOperacao = app(AnttCiotClient::class)->generateIdOperacaoTransporte();

        $this->assertSame('560000563274', $idOperacao);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://antt-hml.test/pefServices/gerar'
                && ! $request->hasHeader('Authorization')
                && $request->data() === ['cpfCnpj' => '12563112000130', 'cnpj' => '12563112000130'];
        });
    }

    public function test_declare_retries_with_token_after_401(): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'fresh'], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::sequence()
                ->push(['Message' => 'Acesso Negado'], 401)
                ->push(['Codigo' => '110', 'Mensagem' => 'Dados inseridos'], 200),
        ]);

        $response = app(AnttCiotClient::class)->declare(['IdOperacaoTransporte' => '260921123456']);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('110', $response->codigo());

        $declaracoes = Http::recorded(
            fn ($request, $response): bool => str_contains($request->url(), '/api/DeclaracaoOperacaoTransporte'),
        )->pluck(0);

        $this->assertCount(2, $declaracoes);
        $this->assertFalse($declaracoes->first()->hasHeader('Authorization'));
        $this->assertTrue($declaracoes->last()->hasHeader('Authorization', 'Bearer fresh'));
    }

    public function test_declare_throws_retryable_exception_on_server_error(): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response('boom', 503),
        ]);

        try {
            app(AnttCiotClient::class)->declare([]);
            $this->fail('Expected AnttCiotException.');
        } catch (AnttCiotException $exception) {
            $this->assertSame(503, $exception->httpStatus);
            $this->assertTrue($exception->retryable());
        }
    }

    public function test_cancel_posts_to_the_cancel_path(): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/CancelamentoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Cancelado',
            ], 200),
        ]);

        $response = app(AnttCiotClient::class)->cancel('520031583158ABCD', 'teste');

        $this->assertTrue($response->isSuccess());

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/api/CancelamentoOperacaoTransporte')
                && $request->data()['CodigoIdentificacaoOperacao'] === '520031583158ABCD'
                && $request->data()['MotivoCancelamento'] === 'teste';
        });
    }

    public function test_encerrar_posts_only_the_ciot_code(): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/EncerramentoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
            ], 200),
        ]);

        app(AnttCiotClient::class)->encerrar('520031583158ABCD');

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/api/EncerramentoOperacaoTransporte')
                && $request->data() === ['CodigoIdentificacaoOperacao' => '520031583158ABCD'];
        });
    }

    public function test_simplified_generate_posts_the_cnpj_root_property(): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '560000563230'],
            ], 200),
        ]);

        $response = app(AnttCiotClient::class)->simplifiedGenerate('12563112000130');

        $this->assertTrue($response->isSuccess());

        Http::assertSent(fn ($request): bool => $request->data() === ['cpfCnpj' => '12563112000130']);
    }

    public function test_http_options_force_tls_1_2_and_include_the_certificate(): void
    {
        config([
            'ciot.cert_path' => '/tmp/cert.pfx',
            'ciot.cert_password' => 'secret',
        ]);

        $client = app(AnttCiotClient::class);

        $options = $this->invokeHttpOptions($client);

        $this->assertSame(CURL_SSLVERSION_TLSv1_2, $options[CURLOPT_SSLVERSION]);
        $this->assertSame(CURL_HTTP_VERSION_1_1, $options[CURLOPT_HTTP_VERSION]);
        $this->assertSame('/tmp/cert.pfx', $options['curl'][CURLOPT_SSLCERT]);
        $this->assertSame('P12', $options['curl'][CURLOPT_SSLCERTTYPE]);
        $this->assertSame('secret', $options['curl'][CURLOPT_SSLKEYPASSWD]);

        config(['ciot.cert_path' => '/tmp/cert.pem']);

        $options = $this->invokeHttpOptions($client);

        $this->assertSame('/tmp/cert.pem', $options['cert']);
        $this->assertSame(['/tmp/cert.pem', 'secret'], $options['ssl_key']);

        config(['ciot.cert_path' => null]);

        $options = $this->invokeHttpOptions($client);

        $this->assertSame(CURL_SSLVERSION_TLSv1_2, $options[CURLOPT_SSLVERSION]);
        $this->assertArrayNotHasKey('curl', $options);
    }

    /**
     * @return array<string, mixed>
     */
    protected function invokeHttpOptions(AnttCiotClient $client): array
    {
        $method = new \ReflectionMethod($client, 'httpOptions');
        $method->setAccessible(true);

        return $method->invoke($client);
    }
}
