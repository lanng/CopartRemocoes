<?php

namespace App\Http\Controllers\Api\CteAgent;

use App\Http\Controllers\Controller;
use App\Models\CteAgent;
use App\Services\Cte\ClaimNextCteDocument;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ClaimCteDocumentController extends Controller
{
    public function __invoke(ClaimNextCteDocument $claimNextCteDocument): JsonResponse|Response
    {
        /** @var CteAgent $agent */
        $agent = request()->user();
        $claim = $claimNextCteDocument->handle($agent);

        if (! $claim) {
            return response()->noContent();
        }

        $document = $claim['document'];

        return response()->json([
            'api_version' => config('cte.api_version'),
            'document_id' => $document->public_id,
            'idempotency_key' => $document->idempotency_key,
            'execution_mode' => $document->execution_mode,
            'claim_token' => $claim['claim_token'],
            'claim_expires_at' => $document->claim_expires_at?->toIso8601String(),
            'snapshot' => $document->snapshot,
        ]);
    }
}
