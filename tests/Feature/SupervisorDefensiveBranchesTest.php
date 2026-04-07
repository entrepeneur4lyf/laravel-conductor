<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;

/**
 * Pins the two defensive noop branches in Supervisor::evaluate so future
 * refactors cannot silently change their contract. These paths only fire
 * for edge-case step states but the supervisor's return shape here is
 * relied on by callers (RunProcessor, HTTP layer) to decide whether to
 * continue execution or bail with a noop decision.
 */
it('returns noop when a pending step has neither a skip condition nor a wait_for', function (): void {
    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(condition: null, waitFor: null),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'pending',
                'attempt' => 1,
            ]),
        ],
        overrides: [
            'id' => 'run-supervisor-pending-noop',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    expect($decision->action)->toBe('noop')
        ->and($decision->reason)->toBe('Pending step has no deterministic supervisor action yet.');
});

it('returns noop with an unsupported-status reason when evaluating a step in a non-dispatchable status', function (string $status): void {
    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => $status,
                'attempt' => 1,
            ]),
        ],
        overrides: [
            'id' => 'run-supervisor-status-'.$status,
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    expect($decision->action)->toBe('noop')
        ->and($decision->reason)->toBe(sprintf('Unsupported step status [%s] for evaluation.', $status));
})->with(['running', 'retrying']);
