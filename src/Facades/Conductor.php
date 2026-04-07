<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData start(string $workflow, array $input = [])
 * @method static \Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunResultData continueRun(string $runId)
 * @method static \Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData|null getRun(string $runId)
 * @method static \Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunResultData resumeRun(string $runId, string $resumeToken, array $payload = [])
 * @method static \Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData retryRun(string $runId, int $revision)
 * @method static \Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData cancelRun(string $runId, int $revision)
 *
 * @see \Entrepeneur4lyf\LaravelConductor\Conductor
 */
final class Conductor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Entrepeneur4lyf\LaravelConductor\Conductor::class;
    }
}
