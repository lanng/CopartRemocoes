<?php

namespace App\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Models\CiotVehicle;
use App\Models\CteEmissionBatch;
use Illuminate\Support\Collection;

class PrefillCiotFromBatch
{
    public function __construct(private readonly PrefillCiotFromDocuments $prefill) {}

    /**
     * @return array{form: array<string, mixed>, ranking: string, warnings: list<string>, origins: Collection, patios: Collection}
     */
    public function handle(CteEmissionBatch $batch): array
    {
        $batch->loadMissing('documents');

        return $this->prefill->handle($batch->documents, $batch);
    }

    /**
     * @return Collection<int, CiotVehicle>
     */
    public function lineVehicles(CiotLineEnum $line = CiotLineEnum::VehicleRemoval): Collection
    {
        return $this->prefill->lineVehicles($line);
    }
}
