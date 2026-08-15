<?php

use App\Http\Controllers\Api\CteAgent\ClaimCteDocumentController;
use App\Http\Controllers\Api\CteAgent\HeartbeatController;
use App\Http\Controllers\Api\CteAgent\RecordCteDocumentResultController;
use App\Http\Controllers\Api\CteAgent\UpdateCteDocumentProgressController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/cte-agent')
    ->middleware(['auth:sanctum', 'abilities:cte-agent', 'cte-agent'])
    ->group(function (): void {
        Route::post('/heartbeat', HeartbeatController::class);
        Route::post('/claim', ClaimCteDocumentController::class);
        Route::post('/documents/{document}/progress', UpdateCteDocumentProgressController::class);
        Route::post('/documents/{document}/result', RecordCteDocumentResultController::class);
    });
