<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;

it('skips a pending step when its condition evaluates false and advances to the next step', function (): void {
    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(
                    condition: 'input.should_run == true',
                    onSuccess: 'publish',
                ),
                StepDefinitionData::from([
                    'id' => 'publish',
                    'agent' => 'publisher',
                    'prompt_template_contents' => 'Publish the article.',
                    'retries' => 0,
                    'timeout' => 30,
                    'on_success' => 'complete',
                ]),
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
            'id' => 'run-skip-condition',
            'input' => [
                'should_run' => false,
            ],
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('skip')
        ->and($decision->next_step_id)->toBe('publish')
        ->and($stored)->not->toBeNull()
        ->and($stored?->revision)->toBe(2)
        ->and($stored?->current_step_id)->toBe('publish')
        ->and($stored?->steps)->toHaveCount(2)
        ->and($stored?->steps[0]->status)->toBe('skipped')
        ->and($stored?->steps[1]->step_definition_id)->toBe('publish')
        ->and($stored?->steps[1]->status)->toBe('pending');
});

it('creates a waiting run with a resume token when the step declares wait_for', function (): void {
    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(waitFor: 'approval'),
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
            'id' => 'run-waiting',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('wait')
        ->and($stored)->not->toBeNull()
        ->and($stored?->status)->toBe('waiting')
        ->and($stored?->revision)->toBe(2)
        ->and($stored?->wait)->not->toBeNull()
        ->and($stored?->wait?->wait_type)->toBe('approval')
        ->and($stored?->wait?->resume_token)->not->toBe('')
        ->and($stored?->steps[0]->supervisor_decision?->action)->toBe('wait');
});

it('returns noop when the current step no longer matches the evaluation target', function (): void {
    $run = storeRunState(
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'completed',
                'attempt' => 1,
            ]),
        ],
        overrides: [
            'id' => 'run-stale-step',
            'current_step_id' => 'publish',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('noop')
        ->and($stored)->not->toBeNull()
        ->and($stored?->revision)->toBe(1);
});
