<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use DomainException;
use Illuminate\Support\Facades\DB;

class IssueSyntheticCiotForHomologation
{
    public function isEnabled(): bool
    {
        return (bool) config('dfe.ciot_synthetic_homologation_enabled', false)
            && (string) config('dfe.tp_amb') === '2'
            && in_array((string) config('app.env'), ['local', 'testing'], true);
    }

    public function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new DomainException('A criação de CIOT sintético está disponível somente em homologação, em ambiente local/de testes e com o recurso habilitado.');
        }
    }

    public function handle(Ciot $ciot): Ciot
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($ciot): Ciot {
            $lockedCiot = Ciot::query()
                ->whereKey($ciot->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCiot->status !== CiotStatusEnum::DRAFT) {
                throw new DomainException('Somente um CIOT em rascunho pode ser emitido sinteticamente.');
            }

            $syntheticNumber = $this->syntheticNumber($lockedCiot);
            $pendingCiot = $lockedCiot->transitionTo(CiotStatusEnum::PENDING, [
                'error_code' => null,
                'error_message' => null,
                'response' => null,
            ]);

            return $pendingCiot->transitionTo(CiotStatusEnum::ISSUED, [
                'ciot_number' => $syntheticNumber,
                'verifier_code' => '9999',
                'issued_at' => now(),
                'response' => [
                    'homologation_fixture' => [
                        'synthetic' => true,
                        'environment' => 'homologation',
                        'ciot_number' => $syntheticNumber,
                        'issued_at' => now()->toIso8601String(),
                    ],
                ],
                'error_code' => null,
                'error_message' => null,
            ]);
        });
    }

    private function syntheticNumber(Ciot $ciot): string
    {
        $id = (int) $ciot->getKey();

        if ($id < 1 || $id > 9_999_999_999) {
            throw new DomainException('Não foi possível gerar um número sintético de CIOT para este registro.');
        }

        return '99'.str_pad((string) $id, 10, '0', STR_PAD_LEFT);
    }
}
