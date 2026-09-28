<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Filament\Resources\CiotResource;
use App\Models\Ciot;
use App\Models\CteEmissionBatch;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Criação transacional do CIOT de um lote de CT-e: trava a linha do lote e
 * valida o guard de duplicado DENTRO da transação, serializando a corrida
 * entre as duas entradas (defeito D5 — antes o exists() rodava solto).
 */
class CreateCiotForBatch
{
    /**
     * Cria o rascunho vinculado ao lote a partir dos dados do formulário
     * (snapshots de pagantes/veículos, frete em centavos e poda B119).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws DomainException quando o lote já possui um CIOT ativo.
     */
    public function handle(CteEmissionBatch $batch, array $data): Ciot
    {
        return DB::transaction(function () use ($batch, $data): Ciot {
            /** @var CteEmissionBatch $lockedBatch */
            $lockedBatch = CteEmissionBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            $duplicado = Ciot::query()
                ->where('cte_emission_batch_id', $lockedBatch->id)
                ->whereNotIn('status', [CiotStatusEnum::CANCELED->value])
                ->exists();

            if ($duplicado) {
                throw new DomainException('Este lote já possui um CIOT ativo');
            }

            $data['public_id'] = (string) Str::uuid();
            $data['cte_emission_batch_id'] = $lockedBatch->id;
            $data = CiotResource::transformFormData($data);

            /** @var Ciot $ciot */
            $ciot = Ciot::create($data);

            activity()
                ->performedOn($ciot)
                ->log("CIOT gerado pelo lote de CT-e #{$lockedBatch->id}.");

            return $ciot;
        });
    }
}
