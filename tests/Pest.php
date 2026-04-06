<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function workflowFixtureDirectory(): string
{
    return realpath(__DIR__.'/Fixtures/workflows') ?: throw new RuntimeException('Workflow fixtures missing.');
}

function storeEndpointRun(array $steps = [], array $overrides = []): WorkflowRunStateData
{
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
        'snapshot' => makeEndpointWorkflow()->toArray(),
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

function makeEndpointWorkflow(): CompiledWorkflowData
{
    return CompiledWorkflowData::from([
        'name' => 'content-pipeline',
        'version' => 1,
        'compiled_at' => '2026-04-06T12:00:00Z',
        'source_hash' => 'sha256:endpoint-workflow',
        'steps' => [
            StepDefinitionData::from([
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
                'retries' => 2,
                'timeout' => 60,
                'on_success' => 'complete',
            ])->toArray(),
        ],
        'failure_handlers' => [],
        'defaults' => [],
        'description' => 'Endpoint test workflow',
    ]);
}
