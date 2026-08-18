<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AnalyzeController;
use App\Http\Controllers\Api\V1\DocTypeController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(AuthenticateApiToken::class)->group(function (): void {
    Route::post('documents/analyze', AnalyzeController::class);
    Route::get('health', HealthController::class);
    Route::get('doc-types', DocTypeController::class);
});
