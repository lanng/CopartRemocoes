<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use App\Services\Ciot\AnttCiotException;
use App\Services\Ciot\CancelCiot;
use App\Services\Ciot\CloseCiot;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CiotCancelAndCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
        ]);
    }

    public function test_cancels_an_issued_ciot_with_a_reason(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/CancelamentoOperacaoTransporte' => Http::response([
                'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
                'Codigo' => '110',
                'Mensagem' => 'Cancelado com sucesso',
                'DataCancelamento' => '2026-09-22T10:00:00-03:00',
            ], 200),
        ]);

        $ciot = app(CancelCiot::class)->handle($ciot, 'Carga cancelada pelo cliente');

        $this->assertSame(CiotStatusEnum::CANCELED, $ciot->status);
        $this->assertSame('Carga cancelada pelo cliente', $ciot->cancel_reason);
        $this->assertNotNull($ciot->canceled_at);

        Http::assertSent(function ($request) use ($ciot): bool {
            return str_contains($request->url(), '/api/CancelamentoOperacaoTransporte')
                && $request->data()['CodigoIdentificacaoOperacao'] === $ciot->fullNumber();
        });
    }

    public function test_refuses_to_cancel_without_a_reason(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('motivo');

        app(CancelCiot::class)->handle($ciot, '   ');
    }

    public function test_refuses_to_cancel_a_ciot_that_is_not_issued(): void
    {
        $ciot = Ciot::factory()->create();

        $this->expectException(DomainException::class);

        app(CancelCiot::class)->handle($ciot, 'motivo');
    }

    public function test_refuses_an_http_200_rejection_even_with_a_protocol(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        Http::fake([
            'https://antt-hml.test/pefServices/api/CancelamentoOperacaoTransporte' => Http::response([
                'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
                'DataCancelamento' => null,
                'Protocolo' => 'N98000000129999',
                'Codigo' => '220',
                'Mensagem' => '["Rejeição: Nao foi encontrada nenhuma Operaçao de Transporte com os dados informados."]',
            ], 200),
        ]);

        try {
            app(CancelCiot::class)->handle($ciot, 'motivo');
            $this->fail('Expected AnttCiotException.');
        } catch (AnttCiotException $exception) {
            $this->assertSame('220', $exception->codigo);
            $this->assertStringContainsString('Operaçao de Transporte', $exception->mensagem);
        }

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->refresh()->status);
    }

    public function test_throws_when_the_antt_rejects_the_cancellation(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/CancelamentoOperacaoTransporte' => Http::response([
                'Mensagem' => 'CIOT inexistente',
            ], 400),
        ]);

        try {
            app(CancelCiot::class)->handle($ciot, 'motivo');
            $this->fail('Expected AnttCiotException.');
        } catch (AnttCiotException $exception) {
            $this->assertSame(400, $exception->httpStatus);
            $this->assertStringContainsString('CIOT inexistente', $exception->getMessage());
        }

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->refresh()->status);
    }

    public function test_closes_an_issued_ciot(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/EncerramentoOperacaoTransporte' => Http::response([
                'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
                'Codigo' => '110',
                'Mensagem' => 'Encerrado com sucesso',
                'DataEncerramento' => '2026-09-22T10:00:00-03:00',
                'Protocolo' => 'N98000000129999',
            ], 200),
        ]);

        $ciot = app(CloseCiot::class)->handle($ciot);

        $this->assertSame(CiotStatusEnum::CLOSED, $ciot->status);
        $this->assertNotNull($ciot->closed_at);

        Http::assertSent(function ($request) use ($ciot): bool {
            return str_contains($request->url(), '/api/EncerramentoOperacaoTransporte')
                && $request->data() === ['CodigoIdentificacaoOperacao' => $ciot->fullNumber()];
        });
    }

    public function test_refuses_an_http_200_close_rejection_even_with_a_protocol(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        Http::fake([
            'https://antt-hml.test/pefServices/api/EncerramentoOperacaoTransporte' => Http::response([
                'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
                'DataEncerramento' => null,
                'Protocolo' => 'N98000000129999',
                'Codigo' => '220',
                'Mensagem' => '["Rejeição: Nao foi encontrada nenhuma Operaçao de Transporte com os dados informados."]',
            ], 200),
        ]);

        try {
            app(CloseCiot::class)->handle($ciot);
            $this->fail('Expected AnttCiotException.');
        } catch (AnttCiotException $exception) {
            $this->assertSame('220', $exception->codigo);
        }

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->refresh()->status);
    }

    public function test_refuses_to_close_a_ciot_that_is_not_issued(): void
    {
        $ciot = Ciot::factory()->create([
            'status' => CiotStatusEnum::CANCELED,
            'ciot_number' => '520031583158',
            'verifier_code' => '1234',
        ]);

        $this->expectException(DomainException::class);

        app(CloseCiot::class)->handle($ciot);
    }
}
