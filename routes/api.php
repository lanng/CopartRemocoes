<?php

use App\Http\Controllers\Api\CteAgent\ClaimCteDocumentController;
use App\Http\Controllers\Api\CteAgent\HeartbeatController;
use App\Http\Controllers\Api\CteAgent\RecordCteDocumentResultController;
use App\Http\Controllers\Api\CteAgent\RecordMdfeDocumentResultController;
use App\Http\Controllers\Api\CteAgent\UpdateCteDocumentProgressController;
use App\Http\Controllers\Api\CteAgent\UpdateMdfeDocumentProgressController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/cte-agent')
    ->middleware(['auth:sanctum', 'abilities:cte-agent', 'cte-agent'])
    ->group(function (): void {
        Route::post('/heartbeat', HeartbeatController::class);
        Route::post('/claim', ClaimCteDocumentController::class);
        Route::post('/documents/{document}/progress', UpdateCteDocumentProgressController::class);
        Route::post('/documents/{document}/result', RecordCteDocumentResultController::class);
        Route::post('/mdfe/documents/{document}/progress', UpdateMdfeDocumentProgressController::class);
        Route::post('/mdfe/documents/{document}/result', RecordMdfeDocumentResultController::class);
    });
