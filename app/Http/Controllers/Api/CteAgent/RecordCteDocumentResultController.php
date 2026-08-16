<?php

namespace App\Http\Controllers\Api\CteAgent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CteAgent\RecordCteDocumentResultRequest;
use App\Models\CteAgent;
use App\Services\Cte\RecordCteAgentResult;
use DomainException;
use Illuminate\Http\JsonResponse;

class RecordCteDocumentResultController extends Controller
{
    public function __invoke(
        RecordCteDocumentResultRequest $request,
        string $document,
        RecordCteAgentResult $recordCteAgentResult,
    ): JsonResponse {
        /** @var CteAgent $agent */
        $agent = $request->user();

        try {
            $result = $recordCteAgentResult->handle($agent, $document, $request->validated());
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
