<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteAgent;
use App\Models\MdfeDocument;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateMdfeDocumentProgress
{
    public function handle(
        CteAgent $agent,
        string $publicId,
        string $claimToken,
        CteDocumentStatusEnum $target,
    ): MdfeDocument {
        return DB::transaction(function () use ($agent, $publicId, $claimToken, $target): MdfeDocument {
            $document = MdfeDocument::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->claimed_by !== $agent->id || ! hash_equals((string) $document->claim_token_hash, hash('sha256', $claimToken))) {
                throw new DomainException('The claim token is invalid for this document.');
            }

            app(MdfeDocumentWorkflow::class)->transition($document, $target);

            if ($target === CteDocumentStatusEnum::AUTHORIZING) {
                $document->forceFill(['authorization_started_at' => now()])->save();
            }

            // Cada progresso autenticado prova que o agent está vivo (o claim
            // token é a fronteira de segurança): renova o lease em todas as
            // transições. A digitação no Lab demora minutos — recusar progresso
            // por lease expirado pausava o agent no meio do fluxo (409) sem
            // marcar falha no documento; o reaper recupera agentes mortos.
            $document->forceFill(['claim_expires_at' => now()->addMinutes(config('cte.claim_lease_minutes'))])->save();

            return $document->refresh();
        });
    }
}
