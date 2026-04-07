<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Conductor;
use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\FailureHandlerData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;
use Entrepeneur4lyf\LaravelConductor\Engine\WorkflowEngine;
use Entrepeneur4lyf\LaravelConductor\Events\RunWaiting;
use Entrepeneur4lyf\LaravelConductor\Events\StepRetrying;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowCancelled;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowCompleted;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowFailed;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowStarted;
use Entrepeneur4lyf\LaravelConductor\Facades\Conductor as ConductorFacade;
use Illuminate\Support\Facades\Event;

it('registers the conductor artisan commands', function (): void {
    $this->artisan('list')
        ->expectsOutputToContain('conductor:validate')
        ->expectsOutputToContain('conductor:make-workflow')
        ->expectsOutputToContain('conductor:status')
        ->expectsOutputToContain('conductor:retry')
        ->expectsOutputToContain('conductor:cancel');
});

it('ships conductor workflow stubs for agent-based workflows', function (): void {
    $workflowStub = packageStubPath('workflow.stub.yaml');
    $schemaStub = packageStubPath('schema.stub.json');
    $promptStub = packageStubPath('prompt.stub.md.j2');

    expect(file_exists($workflowStub))->toBeTrue()
        ->and(file_exists($schemaStub))->toBeTrue()
        ->and(file_exists($promptStub))->toBeTrue()
        ->and(file_get_contents($workflowStub))->toContain('agent:')
        ->and(file_get_contents($workflowStub))->toContain('context_map:')
        ->and(file_get_contents($workflowStub))->toContain('wait_for:')
        ->and(file_get_contents($workflowStub))->toContain('output_schema:')
        ->and(file_get_contents($workflowStub))->not->toContain('worker:')
        ->and(json_decode((string) file_get_contents($schemaStub), true))->toBeArray()
        ->and(file_get_contents($promptStub))->toContain('{{')
        ->and(file_get_contents($promptStub))->toContain('payload');
});

it('dispatches lifecycle events for modeled workflow transitions', function (): void {
    Event::fake([
        WorkflowStarted::class,
        RunWaiting::class,
        StepRetrying::class,
        WorkflowCompleted::class,
        WorkflowFailed::class,
        WorkflowCancelled::class,
    ]);

    config()->set('conductor.definitions.paths', [workflowFixtureDirectory()]);

    app(WorkflowEngine::class)->start('content-pipeline', [
        'topic' => 'Laravel Conductor',
    ]);

    app(Supervisor::class)->evaluate(
        storeLifecycleRun(
            workflow: makeLifecycleWorkflow(
                steps: [
                    makeLifecycleStep(waitFor: 'approval'),
                ],
            ),
            overrides: [
                'id' => 'run-wait',
                'status' => 'running',
                'current_step_id' => 'draft',
            ],
        )->id,
        'draft',
    );

    app(Supervisor::class)->evaluate(
        storeLifecycleRun(
            workflow: makeLifecycleWorkflow(
                steps: [
                    makeLifecycleStep(),
                ],
                failureHandlers: [
                    FailureHandlerData::from([
                        'match' => 'timeout',
                        'action' => 'retry',
                    ]),
                ],
            ),
            steps: [
                StepExecutionStateData::from([
                    'step_definition_id' => 'draft',
                    'status' => 'failed',
                    'attempt' => 1,
                    'error' => 'connection_timeout',
                ]),
            ],
            overrides: [
                'id' => 'run-retry',
                'status' => 'running',
                'current_step_id' => 'draft',
            ],
        )->id,
        'draft',
    );

    app(Supervisor::class)->evaluate(
        storeLifecycleRun(
            workflow: makeLifecycleWorkflow(
                steps: [
                    makeLifecycleStep(),
                ],
            ),
            steps: [
                StepExecutionStateData::from([
                    'step_definition_id' => 'draft',
                    'status' => 'completed',
                    'attempt' => 1,
                    'output' => [
                        'step_id' => 'draft',
                        'run_id' => 'run-complete',
                        'status' => 'completed',
                        'payload' => [
                            'headline' => 'Done',
                        ],
                    ],
                ]),
            ],
            overrides: [
                'id' => 'run-complete',
                'status' => 'running',
                'current_step_id' => 'draft',
            ],
        )->id,
        'draft',
    );

    app(Supervisor::class)->evaluate(
        storeLifecycleRun(
            workflow: makeLifecycleWorkflow(
                steps: [
                    makeLifecycleStep(onSuccess: 'discard'),
                ],
            ),
            steps: [
                StepExecutionStateData::from([
                    'step_definition_id' => 'draft',
                    'status' => 'completed',
                    'attempt' => 1,
                    'output' => [
                        'step_id' => 'draft',
                        'run_id' => 'run-cancel',
                        'status' => 'completed',
                        'payload' => [
                            'headline' => 'Discard me',
                        ],
                    ],
                ]),
            ],
            overrides: [
                'id' => 'run-cancel',
                'status' => 'running',
                'current_step_id' => 'draft',
            ],
        )->id,
        'draft',
    );

    app(Supervisor::class)->evaluate(
        storeLifecycleRun(
            workflow: makeLifecycleWorkflow(),
            steps: [
                StepExecutionStateData::from([
                    'step_definition_id' => 'draft',
                    'status' => 'failed',
                    'attempt' => 1,
                    'error' => 'schema_validation_failed: missing headline',
                ]),
            ],
            overrides: [
                'id' => 'run-fail',
                'status' => 'running',
                'current_step_id' => 'draft',
            ],
        )->id,
        'draft',
    );

    expect(Event::dispatched(WorkflowStarted::class))->not->toBeEmpty()
        ->and(Event::dispatched(RunWaiting::class))->not->toBeEmpty()
        ->and(Event::dispatched(StepRetrying::class))->not->toBeEmpty()
        ->and(Event::dispatched(WorkflowCompleted::class))->not->toBeEmpty()
        ->and(Event::dispatched(WorkflowFailed::class))->not->toBeEmpty()
        ->and(Event::dispatched(WorkflowCancelled::class))->not->toBeEmpty();
});

it('binds the conductor service surface and run lock provider', function (): void {
    expect(app(Conductor::class))->toBeInstanceOf(Conductor::class)
        ->and(app(RunLockProvider::class))->not->toBeNull();
});

it('allows the facade to start and fetch a workflow run', function (): void {
    config()->set('conductor.definitions.paths', [workflowFixtureDirectory()]);

    $started = ConductorFacade::start('content-pipeline-e2e', [
        'topic' => 'Facade topic',
    ]);

    $fetched = ConductorFacade::getRun($started->id);

    expect($started->id)->not->toBeEmpty()
        ->and($started->workflow)->toBe('content-pipeline-e2e')
        ->and($fetched?->id)->toBe($started->id);
});

function packageStubPath(string $relativePath): string
{
    return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.$relativePath;
}

function storeLifecycleRun(
    ?CompiledWorkflowData $workflow = null,
    array $steps = [],
    array $overrides = [],
): WorkflowRunStateData {
    $state = WorkflowRunStateData::from(array_replace_recursive([
        'id' => 'run-default',
        'workflow' => 'content-pipeline',
        'workflow_version' => 1,
        'revision' => 1,
        'status' => 'running',
        'current_step_id' => 'draft',
        'input' => [
            'topic' => 'Laravel Conductor',
        ],
        'snapshot' => ($workflow ?? makeLifecycleWorkflow())->toArray(),
        'steps' => array_map(
            static fn (StepExecutionStateData $step): array => $step->toArray(),
            $steps !== [] ? $steps : [
                StepExecutionStateData::from([
                    'step_definition_id' => 'draft',
                    'status' => 'pending',
                    'attempt' => 1,
                ]),
            ],
        ),
        'timeline' => [],
    ], $overrides));

    return app(WorkflowStateStore::class)->store($state);
}

function makeLifecycleWorkflow(array $steps = [], array $failureHandlers = []): CompiledWorkflowData
{
    return CompiledWorkflowData::from([
        'name' => 'content-pipeline',
        'version' => 1,
        'compiled_at' => '2026-04-06T12:00:00Z',
        'source_hash' => 'sha256:lifecycle-workflow',
        'steps' => array_map(
            static fn (StepDefinitionData $step): array => $step->toArray(),
            $steps !== [] ? $steps : [makeLifecycleStep()],
        ),
        'failure_handlers' => array_map(
            static fn (FailureHandlerData $handler): array => $handler->toArray(),
            $failureHandlers,
        ),
        'defaults' => [],
        'description' => 'Lifecycle test workflow',
    ]);
}

function makeLifecycleStep(
    ?string $waitFor = null,
    string $onSuccess = 'complete',
): StepDefinitionData {
    return StepDefinitionData::from([
        'id' => 'draft',
        'agent' => 'writer',
        'prompt_template_contents' => 'Write a draft.',
        'output_schema_contents' => json_encode([
            'type' => 'object',
            'required' => ['headline'],
            'properties' => [
                'headline' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ], JSON_THROW_ON_ERROR),
        'wait_for' => $waitFor,
        'retries' => 1,
        'timeout' => 60,
        'on_success' => $onSuccess,
    ]);
}
