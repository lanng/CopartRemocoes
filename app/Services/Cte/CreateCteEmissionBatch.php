<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateCteEmissionBatch
{
    /**
     * @param  Collection<int, Register>  $registers
     */
    public function handle(Collection $registers, User $user, string $executionMode): CteEmissionBatch
    {
        if (! in_array($executionMode, ['dry_run', 'live'], true)) {
            throw ValidationException::withMessages(['execution_mode' => 'Modo de execucao invalido.']);
        }

        if ($registers->isEmpty()) {
            throw ValidationException::withMessages(['registers' => 'Selecione ao menos uma remocao.']);
        }

        return DB::transaction(function () use ($registers, $user, $executionMode): CteEmissionBatch {
            $batch = CteEmissionBatch::query()->create([
                'status' => CteEmissionBatchStatusEnum::DRAFT,
                'execution_mode' => $executionMode,
                'created_by' => $user->id,
            ]);

            foreach ($registers as $register) {
                $this->validateRegister($register);

                if ($register->cteDocuments()->where('status', CteDocumentStatusEnum::AUTHORIZED)->exists()) {
                    throw ValidationException::withMessages([
                        'registers' => "A remocao {$register->id} ja possui um CT-e autorizado.",
                    ]);
                }

                $batch->documents()->create([
                    'public_id' => (string) Str::uuid(),
                    'register_id' => $register->id,
                    'status' => CteDocumentStatusEnum::DRAFT,
                    'snapshot' => $this->snapshot($register),
                    'idempotency_key' => (string) Str::uuid(),
                    'execution_mode' => $executionMode,
                ]);
            }

            return $batch->load('documents');
        });
    }

    /** @return array<string, string|int|null> */
    private function snapshot(Register $register): array
    {
        return [
            'schema_version' => 1,
            'register_id' => $register->id,
            'vehicle_id' => (string) $register->vehicle_id,
            'company' => $register->company?->value ?? (string) $register->company,
            'vehicle_model' => (string) $register->vehicle_model,
            'vehicle_plate' => (string) $register->vehicle_plate,
            'origin_city' => (string) $register->origin_city,
            'destination_city' => (string) $register->destination_city,
            'payment_code' => (string) $register->payment_code,
            'insurance' => (string) $register->insurance,
            'fipe_value' => $register->fipe_value !== null ? (string) $register->fipe_value : null,
            'value' => (string) $register->value,
        ];
    }

    private function validateRegister(Register $register): void
    {
        $required = [
            'vehicle_id', 'vehicle_model', 'vehicle_plate', 'origin_city',
            'destination_city', 'payment_code', 'insurance', 'fipe_value', 'value',
        ];

        foreach ($required as $field) {
            if (blank($register->{$field})) {
                throw ValidationException::withMessages([
                    'registers' => "O campo {$field} e obrigatorio na remocao {$register->id}.",
                ]);
            }
        }
    }
}
