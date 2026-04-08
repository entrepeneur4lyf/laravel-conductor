<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;

/**
 * Regression for the post-remediation review finding: StepExecutionStateData::$completed_at
 * was set in-memory by RunProcessor::persistCompleted/persistFailed but silently dropped
 * at the persistence boundary (no migration column, no model property, no syncStepRuns
 * write, no hydrate read). Every API response showed `step.completed_at: null` regardless
 * of actual completion. This test pins the round-trip so the bug cannot regress.
 */
it('round-trips step.completed_at through the database state store', function (): void {
    $completedAt = CarbonImmutable::parse('2026-04-07T12:34:56+00:00')->toIso8601String();

    $run = storeRunState(
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'completed',
                'attempt' => 1,
                'completed_at' => $completedAt,
            ]),
        ],
        overrides: [
            'id' => 'run-completed-at-roundtrip',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $reloaded = app(WorkflowStateStore::class)->get($run->id);

    expect($reloaded)->not->toBeNull()
        ->and($reloaded?->steps)->toHaveCount(1);

    $persistedStep = $reloaded->steps[0];
    expect($persistedStep->completed_at)->not->toBeNull()
        ->and(CarbonImmutable::parse($persistedStep->completed_at)->equalTo(CarbonImmutable::parse($completedAt)))
        ->toBeTrue();
});
