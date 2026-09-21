<?php

namespace App\Console\Commands;

use App\Models\Ciot;
use App\Models\CiotPayer;
use App\Models\CiotVehicle;
use App\Services\Ciot\AnttCiotClient;
use App\Services\Ciot\AnttCiotException;
use App\Services\Ciot\BuildCiotDeclarationPayload;
use App\Services\Ciot\CancelCiot;
use App\Services\Ciot\CloseCiot;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class CiotSmokeCommand extends Command
{
    /**
     * Rota real validada com emissão na homologação (spec, Apêndice B).
     */
    private const ORIGEM = ['cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '17700000', 'ibge' => '3534609'];

    private const DESTINO = ['cidade' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140', 'ibge' => '3508504'];

    private const DISTANCIA_KM = '716';

    protected $signature = 'ciot:smoke
        {--only= : Etapa isolada: token, generate, cancel ou close}
        {--line=vehicle_removal : Linha usada no payload (vehicle_removal ou tank_alcohol)}
        {--force : Permite rodar mesmo com CIOT_ENV=producao}';

    protected $description = 'Testa o ciclo completo de CIOT na ANTT (token, gerar, cancelar, encerrar) em homologação';

    public function handle(AnttCiotClient $client): int
    {
        if (config('ciot.env') === 'producao' && ! $this->option('force')) {
            $this->error('CIOT_ENV=producao. Use --force para rodar o smoke em produção (não recomendado).');

            return self::FAILURE;
        }

        $only = $this->option('only');

        try {
            if ($only === null || $only === 'token') {
                $token = $client->authenticate(force: $only === 'token');
                $this->info('Token OK ('.strlen($token).' caracteres).');

                if ($only === 'token') {
                    return self::SUCCESS;
                }
            }

            if ($only === 'generate') {
                $this->generateOnly($client);

                return self::SUCCESS;
            }

            if ($only === 'cancel') {
                $this->cancelOnly($client);

                return self::SUCCESS;
            }

            if ($only === 'close') {
                $this->closeOnly($client);

                return self::SUCCESS;
            }

            $this->generateAndClose($client);

            usleep(1_100_000); // id_operacao_transporte tem resolução de 1s; evita colisão de chave única

            $this->generateAndCancel($client);
        } catch (AnttCiotException $exception) {
            $this->error('[ANTT] '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('['.class_basename($exception).'] '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function generateAndClose(AnttCiotClient $client): void
    {
        $this->info('=== Ciclo 1: gerar + encerrar ===');

        $ciot = $this->declareTestCiot($client);

        if ($ciot === null) {
            return;
        }

        try {
            app(CloseCiot::class)->handle($ciot);
            $this->info('Encerrado. Resposta: '.json_encode($ciot->response, JSON_UNESCAPED_UNICODE));
        } catch (AnttCiotException $exception) {
            $this->warn($this->describeAnttFailure('Encerramento', $exception));
        }
    }

    protected function generateAndCancel(AnttCiotClient $client): void
    {
        $this->info('=== Ciclo 2: gerar + cancelar ===');

        $ciot = $this->declareTestCiot($client);

        if ($ciot === null) {
            return;
        }

        try {
            app(CancelCiot::class)->handle($ciot, 'CIOT de teste - smoke');
            $this->info('Cancelado. Resposta: '.json_encode($ciot->response, JSON_UNESCAPED_UNICODE));
        } catch (AnttCiotException $exception) {
            $this->warn($this->describeAnttFailure('Cancelamento', $exception));
        }
    }

    protected function describeAnttFailure(string $operation, AnttCiotException $exception): string
    {
        if ($exception->isNotFound()) {
            return sprintf(
                '%s: rota não exposta na homologação (config: ciot.paths). Ajuste o path quando a ANTT publicar.',
                $operation,
            );
        }

        return $operation.' falhou: '.$exception->getMessage();
    }

    protected function generateOnly(AnttCiotClient $client): void
    {
        $this->declareTestCiot($client);
    }

    protected function cancelOnly(AnttCiotClient $client): void
    {
        $ciotNumber = $this->ask('Número do CIOT (16 dígitos)');

        $response = $client->cancel((string) $ciotNumber, 'CIOT de teste - smoke');
        $this->line(json_encode($response->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function closeOnly(AnttCiotClient $client): void
    {
        $ciotNumber = $this->ask('Número do CIOT (16 dígitos)');

        $response = $client->encerrar(['CIOT' => (string) $ciotNumber]);
        $this->line(json_encode($response->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Emite um CIOT de teste na homologação com os dados fixos da rota validada.
     */
    protected function declareTestCiot(AnttCiotClient $client): ?Ciot
    {
        $payer = $this->smokePayer();

        if ($payer === null) {
            return null;
        }

        $tractor = $this->smokeVehicle('PUC8E55');
        $trailer = $this->smokeVehicle('TIX7D32');

        if ($tractor === null || $trailer === null) {
            return null;
        }

        $ciot = Ciot::query()->create([
            'public_id' => (string) Str::uuid(),
            'status' => 'draft',
            'line' => $this->option('line'),
            'operation_type' => 'lotation',
            'payer_id' => $payer->id,
            'payer_cnpj' => $payer->cnpj,
            'payer_name' => $payer->name,
            'delivery_payer_id' => $payer->id,
            'delivery_payer_cnpj' => $payer->cnpj,
            'delivery_payer_name' => $payer->name,
            'additional_payers' => [],
            'origin' => self::ORIGEM,
            'destination' => self::DESTINO,
            'distance_km' => self::DISTANCIA_KM,
            'freight_value_cents' => 100000,
            'cargo_weight_kg' => '20000',
            'vehicles' => [$tractor->snapshot(), $trailer->snapshot()],
            'travel_start_at' => now()->addDay()->startOfDay(),
            'travel_end_at' => now()->addDays(2)->endOfDay(),
        ]);

        $payload = app(BuildCiotDeclarationPayload::class)->handle($ciot);

        $ciot->forceFill([
            'payload' => $payload,
            'id_operacao_transporte' => $payload['IdOperacaoTransporte'],
            'status' => 'pending',
        ])->save();

        $response = $client->declare($payload);

        $this->line('Resposta bruta: '.json_encode($response->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (! $response->isSuccess()) {
            $ciot->forceFill([
                'status' => 'failed',
                'error_code' => $response->codigo(),
                'error_message' => $response->mensagem(),
                'response' => $response->body,
            ])->save();

            $this->error('Geração rejeitada pela ANTT (código '.$response->codigo().').');

            return null;
        }

        $ciot->forceFill([
            'status' => 'issued',
            'ciot_number' => $response->identificacaoOperacao(),
            'verifier_code' => $response->codigoVerificador(),
            'protocol' => $response->protocolo(),
            'carrier_notice' => $response->avisoTransportador(),
            'issued_at' => now(),
            'response' => $response->body,
        ])->save();

        $protocolo = filled($response->protocolo()) ? " (protocolo {$response->protocolo()})" : '';
        $this->info("CIOT gerado: {$response->ciotNumber()}{$protocolo}");

        return $ciot;
    }

    protected function smokePayer(): ?CiotPayer
    {
        $payer = CiotPayer::query()->where('cnpj', '14517191000925')->first();

        if ($payer === null) {
            $this->warn('Pátio Copart Caçapava (14517191000925) não encontrado. Rode o CiotPayerSeeder.');
        }

        return $payer;
    }

    protected function smokeVehicle(string $plate): ?CiotVehicle
    {
        $vehicle = CiotVehicle::query()->where('plate', $plate)->first();

        if ($vehicle === null) {
            $this->warn("Veículo {$plate} não encontrado. Rode o CiotVehicleSeeder.");
        }

        return $vehicle;
    }
}
