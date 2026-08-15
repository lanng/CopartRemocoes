<?php

namespace App\Http\Controllers\Api\CteAgent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CteAgent\HeartbeatRequest;
use App\Models\CteAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class HeartbeatController extends Controller
{
    public function __invoke(HeartbeatRequest $request): JsonResponse
    {
        /** @var CteAgent $agent */
        $agent = $request->user();
        $agent->update([
            'version' => $request->string('agent_version')->toString(),
            'hostname' => $request->string('hostname')->toString(),
            'capabilities' => $request->input('capabilities'),
            'last_seen_at' => now(),
        ]);

        return response()->json([
            'server_time' => Carbon::now('UTC')->toIso8601String(),
            'poll_after_seconds' => config('cte.poll_after_seconds'),
        ]);
    }
}
