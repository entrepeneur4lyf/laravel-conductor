<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('conductor.routes.prefix'))
    ->middleware(config('conductor.routes.middleware'))
    ->group(static function (): void {
        Route::post('/start', [WorkflowController::class, 'start']);
        Route::post('/runs/{runId}/continue', [WorkflowController::class, 'continueRun']);
        Route::get('/runs/{runId}', [WorkflowController::class, 'show']);
        Route::post('/runs/{runId}/resume', [WorkflowController::class, 'resume']);
        Route::post('/runs/{runId}/retry', [WorkflowController::class, 'retry']);
        Route::post('/runs/{runId}/cancel', [WorkflowController::class, 'cancel']);
    });
