<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteAgent;
use App\Models\CteDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimNextCteDocument
{
    /**
     * @return array{document: CteDocument, claim_token: string}|null
     */
    public function handle(CteAgent $agent): ?array
    {
        return DB::transaction(function () use ($agent): ?array {
            CteDocument::query()
                ->whereIn('status', [
                    CteDocumentStatusEnum::CLAIMED,
                    CteDocumentStatusEnum::FILLING,
                    CteDocumentStatusEnum::VALIDATING,
                    CteDocumentStatusEnum::READY_TO_AUTHORIZE,
                ])
                ->where('claim_expires_at', '<', now())
                ->update([
                    'status' => CteDocumentStatusEnum::QUEUED,
                    'claimed_by' => null,
                    'claim_token_hash' => null,
                    'claimed_at' => null,
                    'claim_expires_at' => null,
                ]);

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

            if (! $document) {
                return null;
            }

            $claimToken = Str::random(64);
            $document->forceFill([
                'status' => CteDocumentStatusEnum::CLAIMED,
                'claimed_by' => $agent->id,
                'claim_token_hash' => hash('sha256', $claimToken),
                'claimed_at' => now(),
                'claim_expires_at' => now()->addMinutes(config('cte.claim_lease_minutes')),
            ])->save();

            $document->batch()->update([
                'status' => CteEmissionBatchStatusEnum::PROCESSING,
                'processing_started_at' => Carbon::now(),
            ]);

            return [
                'document' => $document->refresh(),
                'claim_token' => $claimToken,
            ];
        });
    }
}
