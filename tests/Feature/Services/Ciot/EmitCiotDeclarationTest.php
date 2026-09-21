<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Jobs\EmitCiotJob;
use App\Models\Ciot;
use App\Services\Ciot\AnttCiotException;
use App\Services\Ciot\EmitCiotDeclaration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class EmitCiotDeclarationTest extends TestCase
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

    public function test_enqueueing_sets_the_ciot_pending_and_dispatches_the_job(): void
    {
        Queue::fake();

        $ciot = Ciot::factory()->create();

        $ciot = app(EmitCiotDeclaration::class)->handle($ciot);

        $this->assertSame(CiotStatusEnum::PENDING, $ciot->status);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{12}$/', $ciot->id_operacao_transporte);
        $this->assertArrayHasKey('IdOperacaoTransporte', $ciot->payload);

        Queue::assertPushed(EmitCiotJob::class, fn (EmitCiotJob $job): bool => $job->ciotId === $ciot->id);
    }

    public function test_cannot_enqueue_an_issued_ciot(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        $this->expectException(\DomainException::class);

        app(EmitCiotDeclaration::class)->handle($ciot);
    }

    public function test_job_marks_the_ciot_issued_when_antt_accepts(): void
    {
        $ciot = Ciot::factory()->create([
            'status' => CiotStatusEnum::PENDING,
            'payload' => ['IdOperacaoTransporte' => '260921123456'],
            'id_operacao_transporte' => '260921123456',
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados inseridos com sucesso',
                'dados' => ['ciot' => '520031583158'],
                'Protocolo' => '5200315831589999',
                'AvisoTransportador' => 'Exija o comprovante',
            ], 200),
        ]);

        (new EmitCiotJob($ciot->id))->handle(app(\App\Services\Ciot\AnttCiotClient::class));

        $ciot = $ciot->refresh();

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
        $this->assertSame('520031583158', $ciot->ciot_number);
        $this->assertNull($ciot->verifier_code);
        $this->assertSame('5200315831589999', $ciot->protocol);
        $this->assertSame('Exija o comprovante', $ciot->carrier_notice);
        $this->assertNotNull($ciot->issued_at);
    }

    public function test_job_marks_the_ciot_issued_with_a_16_digit_ciot(): void
    {
        $ciot = Ciot::factory()->create([
            'status' => CiotStatusEnum::PENDING,
            'payload' => ['IdOperacaoTransporte' => '260921123456'],
            'id_operacao_transporte' => '260921123456',
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Dados' => ['CIOT' => '5200315831581234'],
            ], 200),
        ]);

        (new EmitCiotJob($ciot->id))->handle(app(\App\Services\Ciot\AnttCiotClient::class));

        $ciot = $ciot->refresh();

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
        $this->assertSame('520031583158', $ciot->ciot_number);
        $this->assertSame('1234', $ciot->verifier_code);
        $this->assertSame('5200315831581234', $ciot->fullNumber());
    }

    public function test_job_marks_the_ciot_failed_on_business_rejection(): void
    {
        $ciot = Ciot::factory()->create([
            'status' => CiotStatusEnum::PENDING,
            'payload' => ['IdOperacaoTransporte' => '260921123456'],
            'id_operacao_transporte' => '260921123456',
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '999',
                'Mensagem' => 'Contratante bloqueado',
            ], 200),
        ]);

        (new EmitCiotJob($ciot->id))->handle(app(\App\Services\Ciot\AnttCiotClient::class));

        $ciot = $ciot->refresh();

        $this->assertSame(CiotStatusEnum::FAILED, $ciot->status);
        $this->assertSame('999', $ciot->error_code);
        $this->assertSame('Contratante bloqueado', $ciot->error_message);
    }

    public function test_job_rethrows_retryable_server_errors(): void
    {
        $ciot = Ciot::factory()->create([
            'status' => CiotStatusEnum::PENDING,
            'payload' => ['IdOperacaoTransporte' => '260921123456'],
            'id_operacao_transporte' => '260921123456',
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response('boom', 500),
        ]);

        try {
            (new EmitCiotJob($ciot->id))->handle(app(\App\Services\Ciot\AnttCiotClient::class));
            $this->fail('Expected AnttCiotException.');
        } catch (AnttCiotException $exception) {
            $this->assertSame(500, $exception->httpStatus);
        }

        $this->assertSame(CiotStatusEnum::PENDING, $ciot->refresh()->status);
    }

    public function test_failed_hook_marks_pending_ciot_as_failed(): void
    {
        $ciot = Ciot::factory()->create([
            'status' => CiotStatusEnum::PENDING,
            'payload' => ['IdOperacaoTransporte' => '260921123456'],
        ]);

        (new EmitCiotJob($ciot->id))->failed(new RuntimeException('attempts exhausted'));

        $ciot = $ciot->refresh();

        $this->assertSame(CiotStatusEnum::FAILED, $ciot->status);
        $this->assertSame('job_exhausted', $ciot->error_code);
        $this->assertSame('attempts exhausted', $ciot->error_message);
    }
}
