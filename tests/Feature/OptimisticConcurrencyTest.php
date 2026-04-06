<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Persistence\DatabaseWorkflowStateStore;
use Illuminate\Support\Facades\DB;

it('advances workflow dossier revision only when the stored revision matches', function (): void {
    $store = app(DatabaseWorkflowStateStore::class);
    $initialState = makeConcurrencyWorkflowRunState(revision: 1, status: 'running');

    $stored = $store->store($initialState);

    $nextState = WorkflowRunStateData::from([
        ...$stored->toArray(),
        'revision' => 2,
        'status' => 'waiting',
        'wait' => [
            'wait_type' => 'approval',
            'resume_token' => 'resume_456',
        ],
    ]);

    $updated = $store->save($nextState, 1);

    expect($updated->revision)->toBe(2)
        ->and($updated->status)->toBe('waiting')
        ->and($updated->wait?->resume_token)->toBe('resume_456');

    $pipelineRow = DB::table('pipeline_runs')->where('id', $updated->id)->first();

    expect($pipelineRow)->not->toBeNull()
        ->and((int) $pipelineRow->revision)->toBe(2)
        ->and($pipelineRow->status)->toBe('waiting');
});

it('rejects stale workflow dossier writes without overwriting the newer revision', function (): void {
    $store = app(DatabaseWorkflowStateStore::class);
    $initialState = makeConcurrencyWorkflowRunState(revision: 1, status: 'running');

    $stored = $store->store($initialState);
    $firstUpdate = WorkflowRunStateData::from([
        ...$stored->toArray(),
        'revision' => 2,
        'status' => 'waiting',
        'wait' => [
            'wait_type' => 'approval',
            'resume_token' => 'resume_456',
        ],
    ]);
    $store->save($firstUpdate, 1);

    $staleUpdate = WorkflowRunStateData::from([
        ...$stored->toArray(),
        'revision' => 2,
        'status' => 'failed',
        'wait' => null,
    ]);

    expect(fn () => $store->save($staleUpdate, 1))
        ->toThrow(RuntimeException::class, 'stale');

    $pipelineRow = DB::table('pipeline_runs')->where('id', $stored->id)->first();

    expect($pipelineRow)->not->toBeNull()
        ->and((int) $pipelineRow->revision)->toBe(2)
        ->and($pipelineRow->status)->toBe('waiting');
});

function makeConcurrencyWorkflowRunState(int $revision, string $status): WorkflowRunStateData
{
    return WorkflowRunStateData::from([
        'id' => 'run_02',
        'workflow' => 'content-pipeline',
        'workflow_version' => 1,
        'revision' => $revision,
        'status' => $status,
        'current_step_id' => 'draft',
        'input' => [
            'topic' => 'laravel',
        ],
        'snapshot' => [
            'name' => 'content-pipeline',
            'version' => 1,
            'compiled_at' => '2026-04-06T12:00:00Z',
            'source_hash' => 'sha256:def456',
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
            'failure_handlers' => [],
            'defaults' => [
                'timeout' => 60,
            ],
        ],
        'steps' => [],
        'timeline' => [],
    ]);
}
