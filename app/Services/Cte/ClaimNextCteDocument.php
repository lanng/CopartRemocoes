<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteAgent;
use App\Models\CteDocument;
use App\Models\MdfeDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimNextCteDocument
{
    /**
     * Fila unificada do agente: prioridade para CT-e; sem CT-e elegível,
     * entrega o próximo MDF-e. O retorno identifica o tipo de documento.
     *
     * @return array{document: CteDocument|MdfeDocument, claim_token: string, document_type: string}|null
     */
    public function handle(CteAgent $agent): ?array
    {
        return DB::transaction(function () use ($agent): ?array {
            $this->requeueExpiredLeases();

            $document = CteDocument::query()
                ->where('status', CteDocumentStatusEnum::QUEUED)
                ->where('execution_mode', $agent->is_dry_run ? 'dry_run' : 'live')
                ->whereHas('batch', function ($query): void {
                    $query->whereIn('status', [
                        CteEmissionBatchStatusEnum::APPROVED,
                        CteEmissionBatchStatusEnum::PROCESSING,
                    ]);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($document !== null) {
                $claimToken = $this->applyClaim($agent, $document);
                $document->batch()->update([
                    'status' => CteEmissionBatchStatusEnum::PROCESSING,
                    'processing_started_at' => Carbon::now(),
                ]);

                return [
                    'document' => $document->refresh(),
                    'claim_token' => $claimToken,
                    'document_type' => 'cte',
                ];
            }

            $mdfe = MdfeDocument::query()
                ->where('status', CteDocumentStatusEnum::QUEUED)
                ->where('execution_mode', $agent->is_dry_run ? 'dry_run' : 'live')
                ->whereHas('batch', function ($query): void {
                    $query->whereIn('status', [
                        CteEmissionBatchStatusEnum::APPROVED,
                        CteEmissionBatchStatusEnum::PROCESSING,
                        CteEmissionBatchStatusEnum::COMPLETED,
                        CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
                    ]);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($mdfe === null) {
                return null;
            }

            $claimToken = $this->applyClaim($agent, $mdfe);

            return [
                'document' => $mdfe->refresh(),
                'claim_token' => $claimToken,
                'document_type' => 'mdfe',
            ];
        });
    }

    /**
     * Aplica o claim (token + lease) a um documento CT-e ou MDF-e e devolve
     * o token em claro (única vez; só o hash fica persistido).
     */
    protected function applyClaim(CteAgent $agent, Model $document): string
    {
        $claimToken = Str::random(64);

        $document->forceFill([
            'status' => CteDocumentStatusEnum::CLAIMED,
            'claimed_by' => $agent->id,
            'claim_token_hash' => hash('sha256', $claimToken),
            'claimed_at' => now(),
            'claim_expires_at' => now()->addMinutes(config('cte.claim_lease_minutes')),
        ])->save();

        return $claimToken;
    }

    protected function requeueExpiredLeases(): void
    {
        $requeue = [
            'status' => CteDocumentStatusEnum::QUEUED,
            'claimed_by' => null,
            'claim_token_hash' => null,
            'claimed_at' => null,
            'claim_expires_at' => null,
        ];

        CteDocument::query()
            ->whereIn('status', [
                CteDocumentStatusEnum::CLAIMED,
                CteDocumentStatusEnum::FILLING,
                CteDocumentStatusEnum::VALIDATING,
                CteDocumentStatusEnum::READY_TO_AUTHORIZE,
                CteDocumentStatusEnum::AUTHORIZING,
                CteDocumentStatusEnum::WAITING_FOR_XML,
            ])
            ->where('claim_expires_at', '<', now())
            ->update($requeue);

        MdfeDocument::query()
            ->whereIn('status', [
                CteDocumentStatusEnum::CLAIMED,
                CteDocumentStatusEnum::FILLING,
                CteDocumentStatusEnum::VALIDATING,
                CteDocumentStatusEnum::READY_TO_AUTHORIZE,
                CteDocumentStatusEnum::AUTHORIZING,
                CteDocumentStatusEnum::WAITING_FOR_XML,
            ])
            ->where('claim_expires_at', '<', now())
            ->update($requeue);
    }
}
