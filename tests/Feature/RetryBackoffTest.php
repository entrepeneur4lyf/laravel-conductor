<?php

declare(strict_types=1);

use Atlasphp\Atlas\AgentRegistry;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Testing\TextResponseFake;
use Carbon\CarbonImmutable;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Data\FailureHandlerData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Engine\RunProcessor;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;

class RetryBackoffRecordingExecutor implements WorkflowStepExecutor
{
    public int $invocations = 0;

    public function execute(string $agentKey, StepInputData $input): StepOutputData
    {
        $this->invocations++;

        return StepOutputData::from([
            'step_id' => $input->step_id,
            'run_id' => $input->run_id,
            'status' => 'completed',
            'payload' => ['headline' => 'recorded executor output'],
        ]);
    }
}

it('stores retry_after when a failure handler specifies a delay', function (): void {
    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(retries: 2),
            ],
            failureHandlers: [
                FailureHandlerData::from([
                    'match' => 'transient_failure',
                    'action' => 'retry',
                    'delay' => 30,
                ]),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'transient_failure',
            ]),
        ],
        overrides: [
            'id' => 'run-retry-backoff-set',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $before = CarbonImmutable::now('UTC');
    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('retry')
        ->and($decision->delay)->toBe(30)
        ->and($stored)->not->toBeNull()
        ->and($stored?->retry_after)->not->toBeNull();

    $retryAfter = CarbonImmutable::parse($stored->retry_after);
    $expected = $before->addSeconds(30);

    // Allow ~5s of slop for test execution time.
    expect(abs($retryAfter->getTimestamp() - $expected->getTimestamp()))->toBeLessThanOrEqual(5);
});

it('does not set retry_after when the failure handler has no delay', function (): void {
    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(retries: 2),
            ],
            failureHandlers: [
                FailureHandlerData::from([
                    'match' => 'transient_failure',
                    'action' => 'retry',
                    'delay' => 0,
                ]),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'transient_failure',
            ]),
        ],
        overrides: [
            'id' => 'run-retry-backoff-zero',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('retry')
        ->and($stored)->not->toBeNull()
        ->and($stored?->retry_after)->toBeNull();
});

it('returns noop on /continue while retry_after is in the future', function (): void {
    $futureRetry = CarbonImmutable::now('UTC')->addMinute()->toIso8601String();

    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(retries: 2),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'pending',
                'attempt' => 2,
            ]),
        ],
        overrides: [
            'id' => 'run-retry-backoff-future',
            'status' => 'running',
            'current_step_id' => 'draft',
            'retry_after' => $futureRetry,
        ],
    );

    $response = $this->postJson("/api/conductor/runs/{$run->id}/continue");

    $response->assertOk()
        ->assertJsonPath('decision.action', 'noop');

    expect((string) $response->json('decision.reason'))->toContain('retry backoff');
});

it('does not call the executor while retry_after is in the future', function (): void {
    $futureRetry = CarbonImmutable::now('UTC')->addMinute()->toIso8601String();

    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(retries: 2),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'pending',
                'attempt' => 2,
            ]),
        ],
        overrides: [
            'id' => 'run-retry-backoff-no-exec',
            'status' => 'running',
            'current_step_id' => 'draft',
            'retry_after' => $futureRetry,
        ],
    );

    $executor = new RetryBackoffRecordingExecutor;
    $this->app->instance(WorkflowStepExecutor::class, $executor);
    $this->app->forgetInstance(RunProcessor::class);

    $response = $this->postJson("/api/conductor/runs/{$run->id}/continue");

    $response->assertOk()
        ->assertJsonPath('decision.action', 'noop');

    expect($executor->invocations)->toBe(0);
});

it('allows /continue once retry_after has elapsed', function (): void {
    $pastRetry = CarbonImmutable::now('UTC')->subSecond()->toIso8601String();

    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(retries: 2),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'pending',
                'attempt' => 2,
            ]),
        ],
        overrides: [
            'id' => 'run-retry-backoff-elapsed',
            'status' => 'running',
            'current_step_id' => 'draft',
            'retry_after' => $pastRetry,
        ],
    );

    $executor = new RetryBackoffRecordingExecutor;
    $this->app->instance(WorkflowStepExecutor::class, $executor);
    $this->app->forgetInstance(RunProcessor::class);

    $response = $this->postJson("/api/conductor/runs/{$run->id}/continue");

    $response->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('decision.action', 'complete');

    expect($executor->invocations)->toBe(1);
});

it('clears retry_after when a successful step execution advances the run', function (): void {
    $pastRetry = CarbonImmutable::now('UTC')->subSecond()->toIso8601String();

    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(retries: 2),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'pending',
                'attempt' => 2,
            ]),
        ],
        overrides: [
            'id' => 'run-retry-backoff-clears',
            'status' => 'running',
            'current_step_id' => 'draft',
            'retry_after' => $pastRetry,
        ],
    );

    $executor = new RetryBackoffRecordingExecutor;
    $this->app->instance(WorkflowStepExecutor::class, $executor);
    $this->app->forgetInstance(RunProcessor::class);

    $this->postJson("/api/conductor/runs/{$run->id}/continue")->assertOk();

    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($stored)->not->toBeNull()
        ->and($stored?->retry_after)->toBeNull();
});

it('clears retry_after when the run is cancelled', function (): void {
    $futureRetry = CarbonImmutable::now('UTC')->addMinute()->toIso8601String();

    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(retries: 2),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'pending',
                'attempt' => 2,
            ]),
        ],
        overrides: [
            'id' => 'run-retry-backoff-cancel',
            'status' => 'running',
            'current_step_id' => 'draft',
            'retry_after' => $futureRetry,
        ],
    );

    $response = $this->postJson("/api/conductor/runs/{$run->id}/cancel", [
        'revision' => $run->revision,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($stored)->not->toBeNull()
        ->and($stored?->retry_after)->toBeNull()
        ->and($stored?->status)->toBe('cancelled');
});

it('does not set retry_after on escalation-driven retries', function (): void {
    app(AgentRegistry::class)->register(SupervisorEscalationTestAgent::class);

    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'retry',
            'reason' => 'AI escalation requested retry.',
            'modified_prompt' => 'Try again with a sharper prompt.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(retries: 2),
            ],
            failureHandlers: [],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'unmatched_error',
                'input' => [
                    'step_id' => 'draft',
                    'run_id' => 'run-retry-backoff-escalation',
                    'rendered_prompt' => 'Original prompt.',
                    'payload' => [],
                ],
            ]),
        ],
        overrides: [
            'id' => 'run-retry-backoff-escalation',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('retry')
        ->and($stored)->not->toBeNull()
        ->and($stored?->retry_after)->toBeNull();
});
