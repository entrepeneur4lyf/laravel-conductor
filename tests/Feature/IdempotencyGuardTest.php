<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\SupervisorDecisionData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\IdempotencyGuard;

/**
 * Build a minimal in-memory WorkflowRunStateData for guard tests. The guard
 * never touches persistence; we just need a well-formed dossier with the
 * caller-controlled status, current_step_id, and steps.
 *
 * @param  array<int, StepExecutionStateData>  $steps
 */
function guardRun(
    string $status = 'running',
    ?string $currentStepId = 'draft',
    array $steps = [],
): WorkflowRunStateData {
    return new WorkflowRunStateData(
        id: 'run-guard',
        workflow: 'content-pipeline',
        workflow_version: 1,
        revision: 1,
        status: $status,
        snapshot: CompiledWorkflowData::from([
            'name' => 'content-pipeline',
            'version' => 1,
            'compiled_at' => '2026-04-07T00:00:00Z',
            'source_hash' => 'sha256:guard',
            'steps' => [
                StepDefinitionData::from([
                    'id' => 'draft',
                    'agent' => 'writer',
                ])->toArray(),
            ],
            'failure_handlers' => [],
            'defaults' => [],
            'description' => 'guard test workflow',
        ]),
        current_step_id: $currentStepId,
        steps: $steps,
    );
}

function guardStep(
    string $stepId = 'draft',
    string $status = 'pending',
    int $attempt = 1,
    ?SupervisorDecisionData $decision = null,
): StepExecutionStateData {
    return StepExecutionStateData::from([
        'step_definition_id' => $stepId,
        'status' => $status,
        'attempt' => $attempt,
        'supervisor_decision' => $decision?->toArray(),
    ]);
}

// ─── forEvaluation ──────────────────────────────────────────────────────

it('returns noop when the run is in a terminal status', function (string $terminal): void {
    $guard = new IdempotencyGuard;
    $step = guardStep();

    $decision = $guard->forEvaluation(
        guardRun(status: $terminal, steps: [$step]),
        $step,
        'draft',
    );

    expect($decision)->not->toBeNull()
        ->and($decision?->action)->toBe('noop')
        ->and($decision?->reason)->toBe('Run is terminal.');
})->with(['completed', 'failed', 'cancelled']);

it('returns noop when current_step_id no longer matches the target step', function (): void {
    $guard = new IdempotencyGuard;
    $step = guardStep(stepId: 'approval');

    $decision = $guard->forEvaluation(
        guardRun(currentStepId: 'approval', steps: [$step]),
        $step,
        'draft',
    );

    expect($decision)->not->toBeNull()
        ->and($decision?->action)->toBe('noop')
        ->and($decision?->reason)->toBe('Current step no longer matches the evaluation target.');
});

it('returns noop when the step execution state cannot be found', function (): void {
    $guard = new IdempotencyGuard;

    $decision = $guard->forEvaluation(
        guardRun(steps: []),
        null,
        'draft',
    );

    expect($decision)->not->toBeNull()
        ->and($decision?->action)->toBe('noop')
        ->and($decision?->reason)->toBe('Step execution state could not be found.');
});

it('returns noop when the step already has a supervisor_decision recorded', function (): void {
    $guard = new IdempotencyGuard;
    $previousDecision = new SupervisorDecisionData(action: 'advance', reason: 'earlier');
    $step = guardStep(decision: $previousDecision);

    $decision = $guard->forEvaluation(
        guardRun(steps: [$step]),
        $step,
        'draft',
    );

    expect($decision)->not->toBeNull()
        ->and($decision?->action)->toBe('noop')
        ->and($decision?->reason)->toBe('Step already has a supervisor decision.');
});

it('returns null (proceed) when the step is fresh and the guard has nothing to short-circuit', function (): void {
    $guard = new IdempotencyGuard;
    $step = guardStep();

    $decision = $guard->forEvaluation(
        guardRun(steps: [$step]),
        $step,
        'draft',
    );

    expect($decision)->toBeNull();
});

// ─── forRetryAttempt ────────────────────────────────────────────────────

it('returns false from forRetryAttempt when the run is terminal', function (string $terminal): void {
    $guard = new IdempotencyGuard;
    $step = guardStep(attempt: 2);

    $proceed = $guard->forRetryAttempt(
        guardRun(status: $terminal, steps: [$step]),
        $step,
        'draft',
        2,
    );

    expect($proceed)->toBeFalse();
})->with(['completed', 'failed', 'cancelled']);

it('returns false from forRetryAttempt when current_step_id has moved past the target step', function (): void {
    $guard = new IdempotencyGuard;
    $step = guardStep(stepId: 'approval', attempt: 2);

    $proceed = $guard->forRetryAttempt(
        guardRun(currentStepId: 'approval', steps: [$step]),
        $step,
        'draft',
        2,
    );

    expect($proceed)->toBeFalse();
});

it('returns false from forRetryAttempt when the step is null', function (): void {
    $guard = new IdempotencyGuard;

    $proceed = $guard->forRetryAttempt(
        guardRun(steps: []),
        null,
        'draft',
        1,
    );

    expect($proceed)->toBeFalse();
});

it('returns false from forRetryAttempt when the step status is not pending', function (string $status): void {
    $guard = new IdempotencyGuard;
    $step = guardStep(status: $status, attempt: 2);

    $proceed = $guard->forRetryAttempt(
        guardRun(steps: [$step]),
        $step,
        'draft',
        2,
    );

    expect($proceed)->toBeFalse();
})->with(['running', 'completed', 'failed', 'skipped', 'retrying']);

it('returns false from forRetryAttempt when the attempt number has changed', function (): void {
    $guard = new IdempotencyGuard;
    $step = guardStep(attempt: 3);

    $proceed = $guard->forRetryAttempt(
        guardRun(steps: [$step]),
        $step,
        'draft',
        2,
    );

    expect($proceed)->toBeFalse();
});

it('returns true from forRetryAttempt when the step is pending on the expected attempt', function (): void {
    $guard = new IdempotencyGuard;
    $step = guardStep(attempt: 2);

    $proceed = $guard->forRetryAttempt(
        guardRun(steps: [$step]),
        $step,
        'draft',
        2,
    );

    expect($proceed)->toBeTrue();
});
