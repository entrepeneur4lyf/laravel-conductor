<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Data\SupervisorDecisionData;
use Entrepeneur4lyf\LaravelConductor\Data\TimelineEntryData;
use Entrepeneur4lyf\LaravelConductor\Data\WaitStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Persistence\DatabaseWorkflowStateStore;
use Illuminate\Support\Facades\DB;

it('stores a revisioned dossier with the compiled snapshot and step history', function (): void {
    $store = app(DatabaseWorkflowStateStore::class);
    $state = makeWorkflowRunState();

    $stored = $store->store($state);

    expect($stored->revision)->toBe(1)
        ->and($stored->snapshot)->toBeInstanceOf(CompiledWorkflowData::class)
        ->and($stored->wait)->toBeInstanceOf(WaitStateData::class)
        ->and($stored->steps)->toHaveCount(1)
        ->and($stored->steps[0])->toBeInstanceOf(StepExecutionStateData::class)
        ->and($stored->steps[0]->input)->toBeInstanceOf(StepInputData::class)
        ->and($stored->steps[0]->output)->toBeInstanceOf(StepOutputData::class)
        ->and($stored->steps[0]->supervisor_decision)->toBeInstanceOf(SupervisorDecisionData::class)
        ->and($stored->timeline[0])->toBeInstanceOf(TimelineEntryData::class);

    $pipelineRow = DB::table('pipeline_runs')->where('id', $stored->id)->first();
    $stepRow = DB::table('step_runs')->where('pipeline_run_id', $stored->id)->first();

    expect($pipelineRow)->not->toBeNull()
        ->and((int) $pipelineRow->revision)->toBe(1)
        ->and(json_decode((string) $pipelineRow->snapshot, true))->toMatchArray($stored->snapshot->toArray())
        ->and(json_decode((string) $pipelineRow->wait, true))->toMatchArray($stored->wait?->toArray() ?? []);

    expect($stepRow)->not->toBeNull()
        ->and($stepRow->step_definition_id)->toBe('draft')
        ->and(json_decode((string) $stepRow->input, true))->toMatchArray($stored->steps[0]->input?->toArray() ?? [])
        ->and(json_decode((string) $stepRow->output, true))->toMatchArray($stored->steps[0]->output?->toArray() ?? [])
        ->and(json_decode((string) $stepRow->supervisor_decision, true))->toMatchArray($stored->steps[0]->supervisor_decision?->toArray() ?? []);

    $rehydrated = $store->get($stored->id);

    expect($rehydrated)->not->toBeNull()
        ->and($rehydrated?->snapshot)->toBeInstanceOf(CompiledWorkflowData::class)
        ->and($rehydrated?->wait)->toBeInstanceOf(WaitStateData::class)
        ->and($rehydrated?->steps[0])->toBeInstanceOf(StepExecutionStateData::class)
        ->and($rehydrated?->steps[0]->input)->toBeInstanceOf(StepInputData::class)
        ->and($rehydrated?->steps[0]->output)->toBeInstanceOf(StepOutputData::class)
        ->and($rehydrated?->steps[0]->supervisor_decision)->toBeInstanceOf(SupervisorDecisionData::class)
        ->and($rehydrated?->timeline[0])->toBeInstanceOf(TimelineEntryData::class)
        ->and($rehydrated?->snapshot->source_hash)->toBe($stored->snapshot->source_hash)
        ->and($rehydrated?->wait?->resume_token)->toBe($stored->wait?->resume_token);
});

it('updates matching step runs in place instead of deleting and recreating them', function (): void {
    $store = app(DatabaseWorkflowStateStore::class);
    $stored = $store->store(makeWorkflowRunState());

    $originalRow = DB::table('step_runs')
        ->where('pipeline_run_id', $stored->id)
        ->where('step_definition_id', 'draft')
        ->where('attempt', 1)
        ->first();

    expect($originalRow)->not->toBeNull();

    $updated = $store->save(
        WorkflowRunStateData::from([
            ...$stored->toArray(),
            'revision' => 2,
            'steps' => [
                StepExecutionStateData::from([
                    ...$stored->steps[0]->toArray(),
                    'status' => 'failed',
                    'error' => 'network_timeout',
                    'output' => [
                        'step_id' => 'draft',
                        'run_id' => 'run_01',
                        'status' => 'failed',
                        'payload' => [
                            'headline' => 'Retry me',
                        ],
                    ],
                ])->toArray(),
            ],
        ]),
        1,
    );

    $stepRows = DB::table('step_runs')
        ->where('pipeline_run_id', $updated->id)
        ->orderBy('attempt')
        ->get();

    expect($stepRows)->toHaveCount(1)
        ->and($stepRows[0]->id)->toBe($originalRow->id)
        ->and($stepRows[0]->created_at)->toBe($originalRow->created_at)
        ->and($stepRows[0]->status)->toBe('failed')
        ->and($stepRows[0]->error)->toBe('network_timeout');
});

function makeWorkflowRunState(): WorkflowRunStateData
{
    return WorkflowRunStateData::from([
        'id' => 'run_01',
        'workflow' => 'content-pipeline',
        'workflow_version' => 1,
        'revision' => 1,
        'status' => 'running',
        'current_step_id' => 'draft',
        'input' => [
            'topic' => 'laravel',
        ],
        'output' => [
            'status' => 'in_progress',
        ],
        'context' => [
            'source' => 'rss',
        ],
        'snapshot' => [
            'name' => 'content-pipeline',
            'version' => 1,
            'compiled_at' => '2026-04-06T12:00:00Z',
            'source_hash' => 'sha256:abc123',
            'steps' => [[
                'id' => 'draft',
                'agent' => 'writer',
                'prompt_template' => 'prompts/draft.md.j2',
                'prompt_template_path' => '/workflows/content-pipeline/prompts/draft.md.j2',
                'prompt_template_contents' => 'Draft the article.',
                'output_schema' => '@schemas/draft-output.json',
                'output_schema_path' => '/workflows/content-pipeline/schemas/draft-output.json',
                'output_schema_contents' => '{"type":"object"}',
                'retries' => 1,
                'timeout' => 60,
                'on_success' => 'complete',
            ]],
            'failure_handlers' => [[
                'match' => 'schema_validation_failed',
                'action' => 'retry',
                'delay' => 30,
                'prompt_template' => 'prompts/repair.md.j2',
                'prompt_template_path' => '/workflows/content-pipeline/prompts/repair.md.j2',
                'prompt_template_contents' => 'Repair the output.',
            ]],
            'defaults' => [
                'timeout' => 60,
            ],
            'description' => 'Content pipeline',
        ],
        'wait' => [
            'wait_type' => 'external_event',
            'resume_token' => 'resume_123',
            'timeout_at' => '2026-04-06T13:00:00Z',
        ],
        'steps' => [[
            'step_definition_id' => 'draft',
            'status' => 'completed',
            'attempt' => 1,
            'batch_index' => 0,
            'input' => [
                'step_id' => 'draft',
                'run_id' => 'run_01',
                'rendered_prompt' => 'Draft the article.',
                'payload' => [
                    'topic' => 'laravel',
                ],
            ],
            'output' => [
                'step_id' => 'draft',
                'run_id' => 'run_01',
                'status' => 'completed',
                'payload' => [
                    'headline' => 'Laravel Conductor',
                ],
            ],
            'error' => null,
            'prompt_override' => null,
            'supervisor_decision' => [
                'action' => 'advance',
                'next_step_id' => 'publish',
                'reason' => 'Ready to continue.',
                'confidence' => 0.95,
            ],
            'supervisor_feedback' => 'Looks good.',
        ]],
        'timeline' => [[
            'type' => 'state_change',
            'message' => 'Workflow initialized.',
            'context' => [
                'revision' => 1,
            ],
            'occurred_at' => '2026-04-06T12:00:00Z',
        ]],
    ]);
}
