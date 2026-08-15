<?php

use App\Http\Controllers\MicrosoftGraphAuthorizationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/microsoft/graph/connect', [MicrosoftGraphAuthorizationController::class, 'connect']);
    Route::get('/microsoft/graph/callback', [MicrosoftGraphAuthorizationController::class, 'callback']);
});
