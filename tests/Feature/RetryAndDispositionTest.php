<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\FailureHandlerData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Data\SupervisorDecisionData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;
use Entrepeneur4lyf\LaravelConductor\Engine\WorkflowEngine;

it('starts a workflow with revision 1, a compiled snapshot, and an initial pending step', function (): void {
    $directory = createWorkflowDefinitionDirectory('content-pipeline', <<<'YAML'
name: content-pipeline
version: 1
description: Test workflow
steps:
  - id: draft
    agent: writer
    prompt_template: prompts/draft.md.j2
    output_schema: "@schemas/draft-output.json"
    retries: 1
    timeout: 60
    on_success: complete
failure_handlers: []
YAML);

    config()->set('conductor.definitions.paths', [$directory]);

    $run = app(WorkflowEngine::class)->start('content-pipeline', [
        'topic' => 'Laravel Conductor',
    ]);

    expect($run->revision)->toBe(1)
        ->and($run->status)->toBe('initializing')
        ->and($run->current_step_id)->toBe('draft')
        ->and($run->snapshot)->toBeInstanceOf(CompiledWorkflowData::class)
        ->and($run->steps)->toHaveCount(1)
        ->and($run->steps[0]->step_definition_id)->toBe('draft')
        ->and($run->steps[0]->status)->toBe('pending');
});

it('evaluates schema validation before quality rules and takes the matching deterministic retry path', function (): void {
    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(
                    output_schema_contents: json_encode([
                        'type' => 'object',
                        'required' => ['headline'],
                        'properties' => [
                            'headline' => ['type' => 'string'],
                            'score' => ['type' => 'integer'],
                        ],
                        'additionalProperties' => false,
                    ], JSON_THROW_ON_ERROR),
                    quality_rules: ['output.score >= 5'],
                    retries: 2,
                ),
            ],
            failureHandlers: [
                FailureHandlerData::from([
                    'match' => 'schema_validation_failed',
                    'action' => 'retry',
                    'delay' => 15,
                ]),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'completed',
                'attempt' => 1,
                'output' => [
                    'step_id' => 'draft',
                    'run_id' => 'run-schema-first',
                    'status' => 'completed',
                    'payload' => [
                        'score' => 1,
                    ],
                ],
            ]),
        ],
        overrides: [
            'id' => 'run-schema-first',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('retry')
        ->and($decision->delay)->toBe(15)
        ->and($stored)->not->toBeNull()
        ->and($stored?->revision)->toBe(2)
        ->and($stored?->steps)->toHaveCount(2)
        ->and($stored?->steps[0]->status)->toBe('retrying')
        ->and($stored?->steps[1]->status)->toBe('pending')
        ->and($stored?->steps[1]->attempt)->toBe(2);
});

it('falls through to fail when retry budget is exhausted', function (): void {
    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(retries: 1),
            ],
            failureHandlers: [
                FailureHandlerData::from([
                    'match' => 'timeout',
                    'action' => 'retry',
                    'delay' => 10,
                ]),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 2,
                'error' => 'connection_timeout',
            ]),
        ],
        overrides: [
            'id' => 'run-exhausted',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('fail')
        ->and($stored)->not->toBeNull()
        ->and($stored?->status)->toBe('failed')
        ->and($stored?->revision)->toBe(2)
        ->and($stored?->steps[0]->supervisor_decision?->action)->toBe('fail');
});

it('stores a prompt override when retry_with_prompt matches', function (): void {
    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [
                makeStepDefinition(retries: 2),
            ],
            failureHandlers: [
                FailureHandlerData::from([
                    'match' => 'schema_validation_failed',
                    'action' => 'retry_with_prompt',
                    'prompt_template' => 'prompts/fix-schema.md.j2',
                    'prompt_template_contents' => 'Repair the response for {{ step_id }} because {{ error }}.',
                ]),
            ],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'schema_validation_failed: missing headline',
            ]),
        ],
        overrides: [
            'id' => 'run-retry-prompt',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');
    $stored = app(WorkflowStateStore::class)->get($run->id);

    expect($decision->action)->toBe('retry_with_prompt')
        ->and($stored)->not->toBeNull()
        ->and($stored?->steps)->toHaveCount(2)
        ->and($stored?->steps[1]->prompt_override)->toBe('Repair the response for draft because schema_validation_failed: missing headline.');
});

it('returns noop when evaluation is duplicated or the run is terminal', function (): void {
    $duplicate = storeRunState(
        workflow: makeCompiledWorkflow(),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'completed',
                'attempt' => 1,
                'supervisor_decision' => [
                    'action' => 'advance',
                    'reason' => 'Already decided.',
                ],
                'output' => [
                    'step_id' => 'draft',
                    'run_id' => 'run-duplicate',
                    'status' => 'completed',
                    'payload' => [
                        'headline' => 'Done',
                    ],
                ],
            ]),
        ],
        overrides: [
            'id' => 'run-duplicate',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $terminal = storeRunState(
        workflow: makeCompiledWorkflow(),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'completed',
                'attempt' => 1,
            ]),
        ],
        overrides: [
            'id' => 'run-terminal',
            'status' => 'completed',
            'current_step_id' => null,
        ],
    );

    $duplicateDecision = app(Supervisor::class)->evaluate($duplicate->id, 'draft');
    $terminalDecision = app(Supervisor::class)->evaluate($terminal->id, 'draft');

    expect($duplicateDecision->action)->toBe('noop')
        ->and($terminalDecision->action)->toBe('noop');
});

function storeRunState(
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
            'should_run' => true,
        ],
        'snapshot' => ($workflow ?? makeCompiledWorkflow())->toArray(),
        'steps' => array_map(
            static fn (StepExecutionStateData $step): array => $step->toArray(),
            $steps,
        ),
        'timeline' => [],
    ], $overrides));

    return app(WorkflowStateStore::class)->store($state);
}

function makeCompiledWorkflow(array $steps = [], array $failureHandlers = []): CompiledWorkflowData
{
    return CompiledWorkflowData::from([
        'name' => 'content-pipeline',
        'version' => 1,
        'compiled_at' => '2026-04-06T12:00:00Z',
        'source_hash' => 'sha256:test-workflow',
        'steps' => array_map(
            static fn (StepDefinitionData $step): array => $step->toArray(),
            $steps !== [] ? $steps : [makeStepDefinition()],
        ),
        'failure_handlers' => array_map(
            static fn (FailureHandlerData $handler): array => $handler->toArray(),
            $failureHandlers,
        ),
        'defaults' => [],
        'description' => 'Test workflow',
    ]);
}

function makeStepDefinition(
    ?string $condition = null,
    ?string $waitFor = null,
    ?string $onSuccess = 'complete',
    int $retries = 1,
    ?array $quality_rules = null,
    ?string $output_schema_contents = null,
): StepDefinitionData {
    return StepDefinitionData::from([
        'id' => 'draft',
        'agent' => 'writer',
        'prompt_template' => 'prompts/draft.md.j2',
        'prompt_template_contents' => 'Write a draft.',
        'output_schema' => '@schemas/draft-output.json',
        'output_schema_contents' => $output_schema_contents ?? json_encode([
            'type' => 'object',
            'required' => ['headline'],
            'properties' => [
                'headline' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ], JSON_THROW_ON_ERROR),
        'wait_for' => $waitFor,
        'retries' => $retries,
        'timeout' => 60,
        'on_success' => $onSuccess,
        'condition' => $condition,
        'quality_rules' => $quality_rules,
    ]);
}

function createWorkflowDefinitionDirectory(string $name, string $yaml): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'conductor-workflow-'.bin2hex(random_bytes(5));
    $promptDirectory = $directory.DIRECTORY_SEPARATOR.'prompts';
    $schemaDirectory = $directory.DIRECTORY_SEPARATOR.'schemas';

    mkdir($promptDirectory, 0777, true);
    mkdir($schemaDirectory, 0777, true);

    file_put_contents($directory.DIRECTORY_SEPARATOR.$name.'.yaml', $yaml);
    file_put_contents($promptDirectory.DIRECTORY_SEPARATOR.'draft.md.j2', 'Write a draft about {{ topic }}.');
    file_put_contents($schemaDirectory.DIRECTORY_SEPARATOR.'draft-output.json', json_encode([
        'type' => 'object',
        'required' => ['headline'],
        'properties' => [
            'headline' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ], JSON_THROW_ON_ERROR));

    return $directory;
}
