<?php

declare(strict_types=1);

use Atlasphp\Atlas\AgentRegistry;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Testing\TextResponseFake;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\FailureHandlerData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;

/**
 * Active assertions for F10 — per-step `on_fail` is consumed by the
 * supervisor as a fallback transition target after failure handlers
 * and escalation are exhausted.
 *
 * Cascade order:
 *
 *   1. Failure handler matches              → handler.action runs
 *   2. No handler match, retry budget left  → escalate
 *   3. No handler match, no retries left    → on_fail (if set) → fail
 *   4. Escalation returns retry             → retry
 *   5. Escalation returns skip              → skip
 *   6. Escalation returns fail              → on_fail (if set) → fail
 */
function makeOnFailWorkflow(?string $onFail, array $additionalSteps = []): CompiledWorkflowData
{
    return makeCompiledWorkflow(
        steps: [
            StepDefinitionData::from([
                'id' => 'draft',
                'agent' => 'writer',
                'prompt_template' => 'prompts/draft.md.j2',
                'prompt_template_contents' => 'Draft.',
                'retries' => 0,
                'timeout' => 60,
                'on_success' => 'complete',
                'on_fail' => $onFail,
            ]),
            ...$additionalSteps,
        ],
        failureHandlers: [],
    );
}

it('routes to the on_fail target when no failure handler matches and no retry budget remains', function (): void {
    $run = storeRunState(
        workflow: makeOnFailWorkflow(
            onFail: 'cleanup',
            additionalSteps: [
                StepDefinitionData::from([
                    'id' => 'cleanup',
                    'agent' => 'cleaner',
                    'prompt_template' => 'prompts/cleanup.md.j2',
                    'prompt_template_contents' => 'Cleanup.',
                    'on_success' => 'complete',
                ]),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'unhandled_error',
            ]),
        ],
        overrides: [
            'id' => 'run-on-fail-step-target',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    // Decision should be advance with next_step_id pointing at cleanup.
    expect($decision->action)->toBe('advance')
        ->and($decision->next_step_id)->toBe('cleanup');

    $stored = app(WorkflowStateStore::class)->get($run->id);
    expect($stored)->not->toBeNull()
        ->and($stored?->status)->toBe('running')
        ->and($stored?->current_step_id)->toBe('cleanup');
});

it('routes to the on_fail terminal target [complete] when configured', function (): void {
    $run = storeRunState(
        workflow: makeOnFailWorkflow(onFail: 'complete'),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'unhandled_error',
            ]),
        ],
        overrides: [
            'id' => 'run-on-fail-complete',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    expect($decision->action)->toBe('complete');

    $stored = app(WorkflowStateStore::class)->get($run->id);
    expect($stored?->status)->toBe('completed');
});

it('routes to the on_fail terminal target [discard] when configured', function (): void {
    $run = storeRunState(
        workflow: makeOnFailWorkflow(onFail: 'discard'),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'unhandled_error',
            ]),
        ],
        overrides: [
            'id' => 'run-on-fail-discard',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    expect($decision->action)->toBe('cancel');

    $stored = app(WorkflowStateStore::class)->get($run->id);
    expect($stored?->status)->toBe('cancelled');
});

it('falls through to fail when on_fail is not declared', function (): void {
    $run = storeRunState(
        workflow: makeOnFailWorkflow(onFail: null),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'unhandled_error',
            ]),
        ],
        overrides: [
            'id' => 'run-on-fail-none',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    expect($decision->action)->toBe('fail');

    $stored = app(WorkflowStateStore::class)->get($run->id);
    expect($stored?->status)->toBe('failed');
});

it('does not transition to on_fail when a failure handler matches and skips', function (): void {
    $stepWithOnFail = StepDefinitionData::from([
        'id' => 'draft',
        'agent' => 'writer',
        'prompt_template' => 'prompts/draft.md.j2',
        'prompt_template_contents' => 'Draft.',
        'retries' => 0,
        'timeout' => 60,
        'on_success' => 'complete',
        'on_fail' => 'cleanup',
    ]);

    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                $stepWithOnFail,
                StepDefinitionData::from([
                    'id' => 'cleanup',
                    'agent' => 'cleaner',
                    'prompt_template' => 'prompts/cleanup.md.j2',
                    'prompt_template_contents' => 'Cleanup.',
                    'on_success' => 'complete',
                ]),
            ],
            failureHandlers: [
                FailureHandlerData::from([
                    'match' => 'unhandled_error',
                    'action' => 'skip',
                ]),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'unhandled_error',
            ]),
        ],
        overrides: [
            'id' => 'run-on-fail-handler-wins',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    // Skip handler advances via on_success ('complete'), NOT via
    // on_fail ('cleanup'). The on_fail target is bypassed because
    // the handler handled the failure.
    expect($decision->action)->toBe('complete');

    $stored = app(WorkflowStateStore::class)->get($run->id);
    expect($stored?->status)->toBe('completed');
});

it('does not transition to on_fail when escalation returns retry', function (): void {
    app(AgentRegistry::class)->register(SupervisorEscalationTestAgent::class);

    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'retry',
            'reason' => 'AI escalation requested retry.',
            'modified_prompt' => 'Try again.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $run = storeRunState(
        workflow: makeOnFailWorkflow(onFail: 'cleanup', additionalSteps: [
            StepDefinitionData::from([
                'id' => 'cleanup',
                'agent' => 'cleaner',
                'prompt_template' => 'prompts/cleanup.md.j2',
                'prompt_template_contents' => 'Cleanup.',
                'on_success' => 'complete',
            ]),
        ]),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'unhandled_error',
                'input' => [
                    'step_id' => 'draft',
                    'run_id' => 'run-on-fail-escalation-retry',
                    'rendered_prompt' => 'Original prompt.',
                    'payload' => [],
                ],
            ]),
        ],
        overrides: [
            'id' => 'run-on-fail-escalation-retry',
            'status' => 'running',
            'current_step_id' => 'draft',
            // retries=2 so escalation has budget to retry
            'snapshot' => makeOnFailWorkflow(onFail: 'cleanup', additionalSteps: [
                StepDefinitionData::from([
                    'id' => 'cleanup',
                    'agent' => 'cleaner',
                    'prompt_template' => 'prompts/cleanup.md.j2',
                    'prompt_template_contents' => 'Cleanup.',
                    'on_success' => 'complete',
                ]),
            ])->toArray(),
        ],
    );

    // Override the draft step in the snapshot to allow retries
    $snapshot = $run->snapshot->toArray();
    $snapshot['steps'][0]['retries'] = 2;
    app(WorkflowStateStore::class)->save(
        WorkflowRunStateData::from([
            ...$run->toArray(),
            'revision' => $run->revision + 1,
            'snapshot' => $snapshot,
        ]),
        $run->revision,
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    // Escalation said retry; the supervisor should NOT route to on_fail.
    expect($decision->action)->toBe('retry');

    $stored = app(WorkflowStateStore::class)->get($run->id);
    expect($stored?->current_step_id)->toBe('draft')
        ->and($stored?->status)->toBe('running');
});

it('routes to on_fail when escalation returns fail', function (): void {
    app(AgentRegistry::class)->register(SupervisorEscalationTestAgent::class);

    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'fail',
            'reason' => 'AI escalation said fail.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $run = storeRunState(
        workflow: makeOnFailWorkflow(onFail: 'cleanup', additionalSteps: [
            StepDefinitionData::from([
                'id' => 'cleanup',
                'agent' => 'cleaner',
                'prompt_template' => 'prompts/cleanup.md.j2',
                'prompt_template_contents' => 'Cleanup.',
                'on_success' => 'complete',
            ]),
        ]),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'unhandled_error',
                'input' => [
                    'step_id' => 'draft',
                    'run_id' => 'run-on-fail-escalation-fail',
                    'rendered_prompt' => 'Original prompt.',
                    'payload' => [],
                ],
            ]),
        ],
        overrides: [
            'id' => 'run-on-fail-escalation-fail',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    // Bump retries on snapshot so escalation runs.
    $snapshot = $run->snapshot->toArray();
    $snapshot['steps'][0]['retries'] = 2;
    app(WorkflowStateStore::class)->save(
        WorkflowRunStateData::from([
            ...$run->toArray(),
            'revision' => $run->revision + 1,
            'snapshot' => $snapshot,
        ]),
        $run->revision,
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    // Escalation said fail; supervisor should route to on_fail (cleanup),
    // not directly to fail.
    expect($decision->action)->toBe('advance')
        ->and($decision->next_step_id)->toBe('cleanup');

    $stored = app(WorkflowStateStore::class)->get($run->id);
    expect($stored?->status)->toBe('running')
        ->and($stored?->current_step_id)->toBe('cleanup');
});
