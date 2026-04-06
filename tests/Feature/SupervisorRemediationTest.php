<?php

declare(strict_types=1);

use Atlasphp\Atlas\Agent;
use Atlasphp\Atlas\AgentRegistry;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Enums\Provider;
use Atlasphp\Atlas\Testing\TextResponseFake;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\FailureHandlerData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;

class SupervisorEscalationTestAgent extends Agent
{
    public function key(): string
    {
        return 'conductor-supervisor';
    }

    public function provider(): Provider|string|null
    {
        return Provider::OpenAI;
    }

    public function model(): ?string
    {
        return 'gpt-4o-mini';
    }
}

beforeEach(function (): void {
    app(AgentRegistry::class)->register(SupervisorEscalationTestAgent::class);
});

it('escalates unmatched failures when retries remain', function (): void {
    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'retry',
            'reason' => 'AI escalation recommends retry.',
            'modified_prompt' => 'Retry with stricter validation.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $run = storeSupervisorRemediationRun(
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'unmatched_error',
                'input' => [
                    'step_id' => 'draft',
                    'run_id' => 'run-escalate-retry',
                    'rendered_prompt' => 'Original prompt.',
                    'payload' => [],
                ],
                'output' => [
                    'step_id' => 'draft',
                    'run_id' => 'run-escalate-retry',
                    'status' => 'failed',
                    'payload' => [
                        'text' => 'Partial response',
                    ],
                ],
            ]),
        ],
        overrides: [
            'id' => 'run-escalate-retry',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $fresh = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('retry')
        ->and($decision->modified_prompt)->toContain('stricter validation')
        ->and($fresh)->not->toBeNull()
        ->and($fresh?->revision)->toBe(2)
        ->and($fresh?->status)->toBe('running')
        ->and($fresh?->steps)->toHaveCount(2)
        ->and($fresh?->steps[1]->status)->toBe('pending')
        ->and($fresh?->steps[1]->attempt)->toBe(2);
});

it('escalates when a failure handler explicitly requests escalation', function (): void {
    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'skip',
            'reason' => 'AI escalation recommends skipping this step.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $run = storeSupervisorRemediationRun(
        failureHandlers: [
            FailureHandlerData::from([
                'match' => 'quality_rule_failed',
                'action' => 'escalate',
            ]),
        ],
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'quality_rule_failed: score too low',
                'input' => [
                    'step_id' => 'draft',
                    'run_id' => 'run-escalate-skip',
                    'rendered_prompt' => 'Original prompt.',
                    'payload' => [],
                ],
            ]),
        ],
        overrides: [
            'id' => 'run-escalate-skip',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    expect($decision->action)->toBe('complete')
        ->and($decision->reason)->toContain('Workflow completed');
});

it('fails unmatched failures after the retry budget is exhausted', function (): void {
    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'retry',
            'reason' => 'AI escalation still wants retry.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $run = storeSupervisorRemediationRun(
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 3,
                'error' => 'unmatched_error',
                'input' => [
                    'step_id' => 'draft',
                    'run_id' => 'run-escalate-fail',
                    'rendered_prompt' => 'Original prompt.',
                    'payload' => [],
                ],
            ]),
        ],
        overrides: [
            'id' => 'run-escalate-fail',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $fresh = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('fail')
        ->and($fresh)->not->toBeNull()
        ->and($fresh?->status)->toBe('failed')
        ->and($fresh?->steps)->toHaveCount(1);
});

function storeSupervisorRemediationRun(array $steps, array $failureHandlers = [], array $overrides = []): WorkflowRunStateData
{
    $state = WorkflowRunStateData::from(array_replace_recursive([
        'id' => 'run-remediation-default',
        'workflow' => 'remediation-workflow',
        'workflow_version' => 1,
        'revision' => 1,
        'status' => 'running',
        'current_step_id' => 'draft',
        'input' => [
            'topic' => 'Laravel Conductor',
        ],
        'snapshot' => CompiledWorkflowData::from([
            'name' => 'remediation-workflow',
            'version' => 1,
            'compiled_at' => '2026-04-06T12:00:00Z',
            'source_hash' => 'sha256:remediation',
            'steps' => [
                StepDefinitionData::from([
                    'id' => 'draft',
                    'agent' => 'writer',
                    'retries' => 2,
                    'on_success' => 'complete',
                ])->toArray(),
            ],
            'failure_handlers' => array_map(
                static fn (FailureHandlerData $handler): array => $handler->toArray(),
                $failureHandlers,
            ),
            'defaults' => [],
        ])->toArray(),
        'steps' => array_map(
            static fn (StepExecutionStateData $step): array => $step->toArray(),
            $steps,
        ),
        'timeline' => [],
    ], $overrides));

    return app(WorkflowStateStore::class)->store($state);
}
