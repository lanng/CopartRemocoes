<?php

namespace App\Http\Controllers\Api\CteAgent;

use App\Enums\CteDocumentStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CteAgent\UpdateMdfeDocumentProgressRequest;
use App\Models\CteAgent;
use App\Services\Cte\UpdateMdfeDocumentProgress;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class UpdateMdfeDocumentProgressController extends Controller
{
    public function __invoke(
        UpdateMdfeDocumentProgressRequest $request,
        string $document,
        UpdateMdfeDocumentProgress $updateMdfeDocumentProgress,
    ): JsonResponse {
        /** @var CteAgent $agent */
        $agent = $request->user();

        try {
            $updatedDocument = $updateMdfeDocumentProgress->handle(
                $agent,
                $document,
                $request->string('claim_token')->toString(),
                CteDocumentStatusEnum::from($request->string('stage')->toString()),
            );
        } catch (DomainException $exception) {
            Log::warning('cte-agent rejection', [
                'agent' => $agent->name,
                'document' => $document,
                'reason' => $exception->getMessage(),
            ]);

            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'document_id' => $updatedDocument->public_id,
            'stage' => $updatedDocument->status->value,
            'claim_expires_at' => $updatedDocument->claim_expires_at?->toIso8601String(),
        ]);
    }
}
