<?php

namespace App\Http\Controllers\Api\CteAgent;

use App\Enums\CteDocumentStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CteAgent\UpdateCteDocumentProgressRequest;
use App\Models\CteAgent;
use App\Services\Cte\UpdateCteDocumentProgress;
use DomainException;
use Illuminate\Http\JsonResponse;

class UpdateCteDocumentProgressController extends Controller
{
    public function __invoke(
        UpdateCteDocumentProgressRequest $request,
        string $document,
        UpdateCteDocumentProgress $updateCteDocumentProgress,
    ): JsonResponse {
        /** @var CteAgent $agent */
        $agent = $request->user();
        try {
            $updatedDocument = $updateCteDocumentProgress->handle(
                $agent,
                $document,
                $request->string('claim_token')->toString(),
                CteDocumentStatusEnum::from($request->string('stage')->toString()),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'document_id' => $updatedDocument->public_id,
            'stage' => $updatedDocument->status->value,
            'claim_expires_at' => $updatedDocument->claim_expires_at?->toIso8601String(),
        ]);
    }
}
