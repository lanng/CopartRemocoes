<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteAgent;
use App\Models\CteDocument;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateCteDocumentProgress
{
    public function handle(
        CteAgent $agent,
        string $publicId,
        string $claimToken,
        CteDocumentStatusEnum $target,
    ): CteDocument {
        return DB::transaction(function () use ($agent, $publicId, $claimToken, $target): CteDocument {
            $document = CteDocument::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->claimed_by !== $agent->id || ! hash_equals((string) $document->claim_token_hash, hash('sha256', $claimToken))) {
                throw new DomainException('The claim token is invalid for this document.');
            }

            app(CteDocumentWorkflow::class)->transition($document, $target);

            if ($target === CteDocumentStatusEnum::AUTHORIZING) {
                $document->forceFill(['authorization_started_at' => now()])->save();
            }

            // Cada progresso autenticado prova que o agent está vivo (o claim
            // token é a fronteira de segurança): renova o lease em todas as
            // transições (mesma regra do MDF-e — o reaper recupera os mortos,
            // o progresso autenticado não pode ser rejeitado por lease).
            $document->forceFill(['claim_expires_at' => now()->addMinutes(config('cte.claim_lease_minutes'))])->save();

            return $document->refresh();
        });
    }
}
