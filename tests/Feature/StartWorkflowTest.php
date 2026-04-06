<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;

it('starts a workflow through the package endpoint', function (): void {
    config()->set('conductor.definitions.paths', [workflowFixtureDirectory()]);

    $response = $this->postJson('/api/conductor/start', [
        'workflow' => 'content-pipeline',
        'input' => [
            'topic' => 'Laravel Conductor',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.workflow', 'content-pipeline')
        ->assertJsonPath('data.status', 'initializing')
        ->assertJsonPath('data.revision', 1)
        ->assertJsonPath('data.current_step_id', 'research')
        ->assertJsonCount(1, 'data.steps');
});

it('returns the current run dossier through the status endpoint', function (): void {
    $run = storeEndpointRun(
        overrides: [
            'id' => 'run-status',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $this->getJson("/api/conductor/runs/{$run->id}")
        ->assertOk()
        ->assertJsonPath('data.id', 'run-status')
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.revision', 1)
        ->assertJsonPath('data.current_step_id', 'draft');
});

it('retries a failed run when the expected revision matches', function (): void {
    $run = storeEndpointRun(
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
            'status' => 'failed',
            'current_step_id' => 'draft',
        ],
    );

    $this->postJson("/api/conductor/runs/{$run->id}/retry", [
        'revision' => 1,
    ])->assertOk()
        ->assertJsonPath('data.id', 'run-retry')
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.revision', 2)
        ->assertJsonPath('data.steps.1.step_definition_id', 'draft')
        ->assertJsonPath('data.steps.1.status', 'pending')
        ->assertJsonPath('data.steps.1.attempt', 2);
});

it('rejects cancel requests when the expected revision is stale', function (): void {
    $initialRun = storeEndpointRun(
        overrides: [
            'id' => 'run-cancel-stale',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $run = app(WorkflowStateStore::class)->save(
        WorkflowRunStateData::from([
            ...$initialRun->toArray(),
            'revision' => 2,
        ]),
        1,
    );

    $this->postJson("/api/conductor/runs/{$run->id}/cancel", [
        'revision' => 1,
    ])->assertStatus(409)
        ->assertJsonPath('message', 'Run revision mismatch.');
});
