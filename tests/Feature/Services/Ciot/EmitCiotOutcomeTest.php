<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CiotEmissionOutcome;
use App\Enums\CiotStatusEnum;
use App\Jobs\EmitCiotJob;
use App\Models\Ciot;
use App\Services\Ciot\AnttCiotException;
use App\Services\Ciot\EmitCiotDeclaration;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class EmitCiotOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.lines.vehicle_removal' => [
                'bank_code' => '756',
                'bank_agency' => '0001',
                'bank_account' => '111',
            ],
        ]);
    }

    public function test_emit_issues_the_ciot_synchronously_when_antt_accepts(): void
    {
        Queue::fake();

        $this->fakeAnttAcceptingTheDeclaration();

        $ciot = Ciot::factory()->create();

        $result = app(EmitCiotDeclaration::class)->emit($ciot);

        $this->assertSame(CiotEmissionOutcome::Issued, $result->outcome);
        $this->assertTrue($result->isIssued());
        $this->assertSame('5200315831589999', $result->fullNumber());
        $this->assertSame(CiotStatusEnum::ISSUED, $result->ciot->status);
        $this->assertNull($result->message);

        Queue::assertNothingPushed();
    }

    public function test_emit_returns_failed_with_the_rejection_message_on_business_rejection(): void
    {
        Queue::fake();

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '560000569999'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '999',
                'Mensagem' => 'Contratante bloqueado',
            ], 200),
        ]);

        $ciot = Ciot::factory()->create();

        $result = app(EmitCiotDeclaration::class)->emit($ciot);

        $this->assertSame(CiotEmissionOutcome::Failed, $result->outcome);
        $this->assertFalse($result->isIssued());
        $this->assertSame('Contratante bloqueado', $result->message);
        $this->assertSame(CiotStatusEnum::FAILED, $result->ciot->status);
        $this->assertSame('Contratante bloqueado', $result->ciot->error_message);

        Queue::assertNothingPushed();
    }

    public function test_emit_returns_invalid_without_reporting_when_a_guard_rule_rejects_the_ciot(): void
    {
        Queue::fake();

        $handler = $this->mock(ExceptionHandler::class);
        $handler->shouldNotReceive('report');

        $ciot = Ciot::factory()->issued()->create();

        $result = app(EmitCiotDeclaration::class)->emit($ciot);

        $this->assertSame(CiotEmissionOutcome::Invalid, $result->outcome);
        $this->assertSame('Este CIOT já foi emitido.', $result->message);
        $this->assertSame(CiotStatusEnum::ISSUED, $result->ciot->status);

        Queue::assertNothingPushed();
    }

    public function test_emit_returns_invalid_when_the_id_generator_rejects_the_request(): void
    {
        Queue::fake();

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response('CNPJ nao autorizado', 400),
        ]);

        $ciot = Ciot::factory()->create();

        $result = app(EmitCiotDeclaration::class)->emit($ciot);

        $this->assertSame(CiotEmissionOutcome::Invalid, $result->outcome);
        $this->assertSame(
            'Geração de IdOperacaoTransporte falhou (HTTP 400): CNPJ nao autorizado',
            $result->message,
        );
        $this->assertSame(CiotStatusEnum::DRAFT, $result->ciot->status);

        Queue::assertNothingPushed();
    }

    public function test_emit_queues_the_job_when_the_declaration_endpoint_fails(): void
    {
        Queue::fake();

        $handler = $this->mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->withArgs(
            fn (Throwable $exception): bool => $exception instanceof AnttCiotException && $exception->httpStatus === 500,
        );

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '560000569999'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response('boom', 500),
        ]);

        $ciot = Ciot::factory()->create();

        $result = app(EmitCiotDeclaration::class)->emit($ciot);

        $this->assertSame(CiotEmissionOutcome::Queued, $result->outcome);
        $this->assertSame(CiotStatusEnum::PENDING, $result->ciot->status);

        Queue::assertPushed(EmitCiotJob::class, fn (EmitCiotJob $job): bool => $job->ciotId === $ciot->id);
    }

    public function test_enqueue_dispatches_the_job_when_prepare_succeeds(): void
    {
        Queue::fake();

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '560000569999'],
            ], 200),
        ]);

        $ciot = Ciot::factory()->create();

        $result = app(EmitCiotDeclaration::class)->enqueue($ciot);

        $this->assertSame(CiotEmissionOutcome::Enqueued, $result->outcome);
        $this->assertSame(CiotStatusEnum::PENDING, $result->ciot->status);
        $this->assertSame('560000569999', $result->ciot->id_operacao_transporte);
        $this->assertNull($result->message);

        Queue::assertPushed(EmitCiotJob::class, fn (EmitCiotJob $job): bool => $job->ciotId === $ciot->id);
    }

    public function test_enqueue_returns_invalid_for_an_issued_ciot(): void
    {
        Queue::fake();

        $ciot = Ciot::factory()->issued()->create();

        $result = app(EmitCiotDeclaration::class)->enqueue($ciot);

        $this->assertSame(CiotEmissionOutcome::Invalid, $result->outcome);
        $this->assertSame('Este CIOT já foi emitido.', $result->message);
        $this->assertSame(CiotStatusEnum::ISSUED, $result->ciot->status);

        Queue::assertNothingPushed();
    }

    public function test_enqueue_returns_invalid_when_the_id_generator_fails(): void
    {
        Queue::fake();

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response('boom', 500),
        ]);

        $ciot = Ciot::factory()->create();

        $result = app(EmitCiotDeclaration::class)->enqueue($ciot);

        $this->assertSame(CiotEmissionOutcome::Invalid, $result->outcome);
        $this->assertSame(CiotStatusEnum::DRAFT, $result->ciot->status);

        Queue::assertNothingPushed();
    }

    public function test_enqueue_returns_errored_on_an_unexpected_failure(): void
    {
        Queue::fake();

        $handler = $this->mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->withArgs(
            fn (Throwable $exception): bool => $exception instanceof RuntimeException && $exception->getMessage() === 'conexao recusada',
        );

        Http::fake(function (): never {
            throw new RuntimeException('conexao recusada');
        });

        $ciot = Ciot::factory()->create();

        $result = app(EmitCiotDeclaration::class)->enqueue($ciot);

        $this->assertSame(CiotEmissionOutcome::Errored, $result->outcome);
        $this->assertSame(CiotStatusEnum::DRAFT, $result->ciot->status);

        Queue::assertNothingPushed();
    }

    public function test_a_ciot_queued_by_emit_gets_prepared_and_issued_by_the_job(): void
    {
        Queue::fake();

        $gerarResponds = false;

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => function () use (&$gerarResponds) {
                return $gerarResponds
                    ? Http::response(['Sucesso' => true, 'Dados' => ['CIOT' => '560000569999']], 200)
                    : Http::response('boom', 500);
            },
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados inseridos com sucesso',
                'dados' => ['ciot' => '520031583158'],
                'Protocolo' => '5200315831589999',
            ], 200),
        ]);

        $ciot = Ciot::factory()->create();

        $result = app(EmitCiotDeclaration::class)->emit($ciot);

        $this->assertSame(CiotEmissionOutcome::Queued, $result->outcome);
        $this->assertSame(CiotStatusEnum::DRAFT, $result->ciot->status);

        Queue::assertPushed(EmitCiotJob::class, fn (EmitCiotJob $job): bool => $job->ciotId === $ciot->id);

        $gerarResponds = true;

        (new EmitCiotJob($ciot->id))->handle(app(EmitCiotDeclaration::class));

        $issued = $ciot->refresh();

        $this->assertSame(CiotStatusEnum::ISSUED, $issued->status);
        $this->assertSame('560000569999', $issued->id_operacao_transporte);
        $this->assertSame('520031583158', $issued->ciot_number);
    }

    public function test_the_job_marks_a_domain_exception_as_a_terminal_failure(): void
    {
        $ciot = Ciot::factory()->create(['distance_km' => '0.00']);

        (new EmitCiotJob($ciot->id))->handle(app(EmitCiotDeclaration::class));

        $failed = $ciot->refresh();

        $this->assertSame(CiotStatusEnum::FAILED, $failed->status);
        $this->assertNull($failed->error_code);
        $this->assertSame('A distância percorrida deve ser maior que zero (regra B82 da ANTT).', $failed->error_message);
    }

    public function test_the_job_does_not_rethrow_when_a_failed_ciot_still_violates_the_rules(): void
    {
        $ciot = Ciot::factory()->create([
            'status' => CiotStatusEnum::FAILED,
            'error_code' => '999',
            'error_message' => 'Contratante bloqueado',
            'distance_km' => '0.00',
        ]);

        (new EmitCiotJob($ciot->id))->handle(app(EmitCiotDeclaration::class));

        $failed = $ciot->refresh();

        $this->assertSame(CiotStatusEnum::FAILED, $failed->status);
        $this->assertSame('A distância percorrida deve ser maior que zero (regra B82 da ANTT).', $failed->error_message);
    }

    /**
     * Stub completo do caminho feliz: gerador de IdOperacaoTransporte,
     * token e declaração aceita com protocolo de 16 dígitos.
     */
    protected function fakeAnttAcceptingTheDeclaration(): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '560000569999'],
            ], 200),
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados inseridos com sucesso',
                'dados' => ['ciot' => '520031583158'],
                'Protocolo' => '5200315831589999',
                'AvisoTransportador' => 'Exija o comprovante',
            ], 200),
        ]);
    }
}
