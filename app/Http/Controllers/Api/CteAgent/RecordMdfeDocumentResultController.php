<?php

namespace App\Http\Controllers\Api\CteAgent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CteAgent\RecordMdfeAgentResultRequest;
use App\Models\CteAgent;
use App\Services\Cte\RecordMdfeAgentResult;
use DomainException;
use Illuminate\Http\JsonResponse;

class RecordMdfeDocumentResultController extends Controller
{
    public function __invoke(
        RecordMdfeAgentResultRequest $request,
        string $document,
        RecordMdfeAgentResult $recordMdfeAgentResult,
    ): JsonResponse {
        /** @var CteAgent $agent */
        $agent = $request->user();

        try {
            $result = $recordMdfeAgentResult->handle(
                $agent,
                $document,
                $request->validated(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'document_id' => $result->public_id,
            'outcome' => $request->string('outcome')->toString(),
            'status' => $result->status->value,
        ]);
    }
}
