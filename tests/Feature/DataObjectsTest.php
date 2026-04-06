<?php

use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\FailureHandlerData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Data\SupervisorDecisionData;
use Entrepeneur4lyf\LaravelConductor\Data\TimelineEntryData;
use Entrepeneur4lyf\LaravelConductor\Data\WaitStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;

it('hydrates nested workflow data objects from arrays', function (): void {
    $definition = WorkflowDefinitionData::from([
        'name' => 'content-pipeline',
        'version' => 1,
        'description' => 'Content pipeline',
        'steps' => [[
            'id' => 'aggregate',
            'agent' => 'aggregator',
            'prompt_template' => 'prompts/aggregate-topics.md.j2',
            'output_schema' => '@schemas/aggregator-output.json',
            'wait_for' => 'external-approval',
            'context_map' => [
                'topic' => 'input.topic',
            ],
            'parallel' => true,
            'foreach' => 'input.items',
            'on_success' => 'complete',
        ]],
        'failure_handlers' => [[
            'match' => 'schema_validation_failed',
            'action' => 'retry',
            'delay' => 30,
        ]],
    ]);

    expect($definition->name)->toBe('content-pipeline')
        ->and($definition->steps[0])->toBeInstanceOf(StepDefinitionData::class)
        ->and($definition->steps[0]->agent)->toBe('aggregator')
        ->and($definition->steps[0]->wait_for)->toBe('external-approval')
        ->and($definition->steps[0]->prompt_template)->toBe('prompts/aggregate-topics.md.j2')
        ->and($definition->steps[0]->output_schema)->toBe('@schemas/aggregator-output.json')
        ->and($definition->steps[0]->context_map)->toBe(['topic' => 'input.topic'])
        ->and($definition->steps[0]->parallel)->toBeTrue()
        ->and($definition->steps[0]->foreach)->toBe('input.items')
        ->and($definition->failure_handlers[0])->toBeInstanceOf(FailureHandlerData::class)
        ->and($definition->failure_handlers[0]->action)->toBe('retry');

    $compiled = CompiledWorkflowData::from([
        'name' => 'content-pipeline',
        'version' => 1,
        'steps' => [
            [
                'id' => 'aggregate',
                'agent' => 'aggregator',
                'prompt_template' => 'prompts/aggregate-topics.md.j2',
                'output_schema' => '@schemas/aggregator-output.json',
                'prompt_template_path' => '/workflows/content-pipeline/prompts/aggregate-topics.md.j2',
                'prompt_template_contents' => 'Aggregate topics into a structured summary.',
                'output_schema_path' => '/workflows/content-pipeline/schemas/aggregator-output.json',
                'output_schema_contents' => '{"type":"object"}',
                'wait_for' => 'external-approval',
                'context_map' => [
                    'topic' => 'input.topic',
                ],
                'parallel' => true,
                'foreach' => 'input.items',
            ],
        ],
        'failure_handlers' => [
            [
                'match' => 'schema_validation_failed',
                'action' => 'retry',
                'delay' => 30,
                'prompt_template' => 'prompts/retry.md.j2',
                'prompt_template_path' => '/workflows/content-pipeline/prompts/retry.md.j2',
                'prompt_template_contents' => 'Retry with more detail.',
            ],
        ],
        'defaults' => [
            'timeout' => 120,
        ],
        'compiled_at' => '2026-04-06T12:00:00Z',
        'source_hash' => 'sha256:2f2e0d0d5a8a7b1c0f6f1f4d2e0b4e87d2f4d1b9d8c6a5b4e3f2a1c0d9e8f7a6',
    ]);

    expect($compiled->steps[0])->toBeInstanceOf(StepDefinitionData::class)
        ->and($compiled->steps[0]->prompt_template)->toBe('prompts/aggregate-topics.md.j2')
        ->and($compiled->steps[0]->output_schema)->toBe('@schemas/aggregator-output.json')
        ->and($compiled->steps[0]->prompt_template_path)->toBe('/workflows/content-pipeline/prompts/aggregate-topics.md.j2')
        ->and($compiled->steps[0]->prompt_template_contents)->toBe('Aggregate topics into a structured summary.')
        ->and($compiled->steps[0]->output_schema_path)->toBe('/workflows/content-pipeline/schemas/aggregator-output.json')
        ->and($compiled->steps[0]->output_schema_contents)->toBe('{"type":"object"}')
        ->and($compiled->steps[0]->prompt_template_path)->not->toBe($compiled->steps[0]->prompt_template)
        ->and($compiled->steps[0]->output_schema_path)->not->toBe($compiled->steps[0]->output_schema)
        ->and($compiled->failure_handlers[0])->toBeInstanceOf(FailureHandlerData::class)
        ->and($compiled->failure_handlers[0]->prompt_template_path)->toBe('/workflows/content-pipeline/prompts/retry.md.j2')
        ->and($compiled->failure_handlers[0]->prompt_template_contents)->toBe('Retry with more detail.')
        ->and($compiled->compiled_at)->toBe('2026-04-06T12:00:00Z')
        ->and($compiled->source_hash)->toBe('sha256:2f2e0d0d5a8a7b1c0f6f1f4d2e0b4e87d2f4d1b9d8c6a5b4e3f2a1c0d9e8f7a6');

    $runState = WorkflowRunStateData::from([
        'id' => 'run_01',
        'workflow' => 'content-pipeline',
        'workflow_version' => 1,
        'revision' => 9,
        'status' => 'waiting',
        'current_step_id' => 'aggregate',
        'input' => [
            'topic' => 'laravel',
        ],
        'snapshot' => $compiled->toArray(),
        'wait' => [
            'wait_type' => 'external_event',
            'resume_token' => 'resume_123',
        ],
        'steps' => [[
            'step_definition_id' => 'aggregate',
            'status' => 'waiting',
            'input' => [
                'step_id' => 'aggregate',
                'run_id' => 'run_01',
                'rendered_prompt' => 'Review the generated summary.',
            ],
            'output' => [
                'step_id' => 'aggregate',
                'run_id' => 'run_01',
                'status' => 'completed',
            ],
            'supervisor_decision' => [
                'action' => 'retry',
                'delay' => 30,
            ],
        ]],
        'timeline' => [[
            'type' => 'state_change',
            'message' => 'Waiting on approval',
        ]],
    ]);

    expect($runState->workflow_version)->toBe(1)
        ->and($runState->revision)->toBe(9)
        ->and($runState->snapshot)->toBeInstanceOf(CompiledWorkflowData::class)
        ->and($runState->snapshot->steps[0]->foreach)->toBe('input.items')
        ->and($runState->wait)->toBeInstanceOf(WaitStateData::class)
        ->and($runState->wait->resume_token)->toBe('resume_123')
        ->and($runState->wait->timeout_at)->toBeNull()
        ->and($runState->wait->metadata)->toBe([])
        ->and($runState->steps[0])->toBeInstanceOf(StepExecutionStateData::class)
        ->and($runState->steps[0]->attempt)->toBe(1)
        ->and($runState->steps[0]->input)->toBeInstanceOf(StepInputData::class)
        ->and($runState->steps[0]->input->previous_output)->toBeNull()
        ->and($runState->steps[0]->input->payload)->toBe([])
        ->and($runState->steps[0]->input->meta)->toBe([])
        ->and($runState->steps[0]->output)->toBeInstanceOf(StepOutputData::class)
        ->and($runState->steps[0]->output->payload)->toBe([])
        ->and($runState->steps[0]->output->metadata)->toBe([])
        ->and($runState->steps[0]->supervisor_decision)->toBeInstanceOf(SupervisorDecisionData::class)
        ->and($runState->steps[0]->supervisor_decision->action)->toBe('retry')
        ->and($runState->steps[0]->supervisor_decision->next_step_id)->toBeNull()
        ->and($runState->steps[0]->supervisor_decision->reason)->toBeNull()
        ->and($runState->steps[0]->supervisor_decision->modified_prompt)->toBeNull()
        ->and($runState->steps[0]->supervisor_decision->confidence)->toBeNull()
        ->and($runState->steps[0]->supervisor_decision->delay)->toBe(30)
        ->and($runState->timeline[0])->toBeInstanceOf(TimelineEntryData::class)
        ->and($runState->timeline[0]->context)->toBe([])
        ->and($runState->timeline[0]->occurred_at)->toBeNull();
});

it('hydrates step execution data defaults from minimal arrays', function (): void {
    $execution = StepExecutionStateData::from([
        'step_definition_id' => 'review',
        'status' => 'waiting',
        'input' => [
            'step_id' => 'review',
            'run_id' => 'run_01',
            'rendered_prompt' => 'Review the draft.',
        ],
        'output' => [
            'step_id' => 'review',
            'run_id' => 'run_01',
            'status' => 'completed',
        ],
        'supervisor_decision' => [
            'action' => 'approve',
        ],
    ]);

    expect($execution->attempt)->toBe(1)
        ->and($execution->batch_index)->toBeNull()
        ->and($execution->input)->toBeInstanceOf(StepInputData::class)
        ->and($execution->input->payload)->toBe([])
        ->and($execution->input->previous_output)->toBeNull()
        ->and($execution->input->meta)->toBe([])
        ->and($execution->output)->toBeInstanceOf(StepOutputData::class)
        ->and($execution->output->payload)->toBe([])
        ->and($execution->output->error)->toBeNull()
        ->and($execution->output->metadata)->toBe([])
        ->and($execution->supervisor_decision)->toBeInstanceOf(SupervisorDecisionData::class)
        ->and($execution->supervisor_decision->action)->toBe('approve')
        ->and($execution->supervisor_decision->next_step_id)->toBeNull()
        ->and($execution->supervisor_decision->reason)->toBeNull()
        ->and($execution->supervisor_decision->modified_prompt)->toBeNull()
        ->and($execution->supervisor_decision->confidence)->toBeNull()
        ->and($execution->supervisor_decision->delay)->toBeNull();
});
