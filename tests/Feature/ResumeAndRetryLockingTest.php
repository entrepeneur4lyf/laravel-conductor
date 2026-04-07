<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Conductor;
use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WaitStateData;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunLockedException;

/**
 * Recording lock provider — invokes the callback so the operation still
 * completes, while exposing how many times withLock was called per run.
 */
class ResumeRetryRecordingLock implements RunLockProvider
{
    /** @var array<int, string> */
    public array $lockedRuns = [];

    public function withLock(string $runId, Closure $callback, int $blockSeconds = 5): mixed
    {
        $this->lockedRuns[] = $runId;

        return $callback();
    }
}

class ResumeRetryThrowingLock implements RunLockProvider
{
    public int $calls = 0;

    public function withLock(string $runId, Closure $callback, int $blockSeconds = 5): mixed
    {
        $this->calls++;

        throw new RunLockedException($runId);
    }
}

it('it wraps resumeRun in a lock', function (): void {
    $run = storeEndpointRun(
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'pending',
                'attempt' => 1,
                'supervisor_decision' => [
                    'action' => 'wait',
                    'reason' => 'Awaiting approval.',
                ],
            ]),
        ],
        overrides: [
            'id' => 'run-lock-resume',
            'status' => 'waiting',
            'current_step_id' => 'draft',
            'wait' => WaitStateData::from([
                'wait_type' => 'approval',
                'resume_token' => 'resume-xyz',
            ])->toArray(),
        ],
    );

    $recorder = new ResumeRetryRecordingLock;
    $this->app->instance(RunLockProvider::class, $recorder);
    $this->app->forgetInstance(Conductor::class);

    $this->postJson("/api/conductor/runs/{$run->id}/resume", [
        'resume_token' => 'resume-xyz',
        'payload' => [
            'headline' => 'Approved',
        ],
    ])->assertOk();

    expect($recorder->lockedRuns)->toContain($run->id);
});

it('it returns 423 from /resume when lock fails', function (): void {
    $run = storeEndpointRun(
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'pending',
                'attempt' => 1,
                'supervisor_decision' => [
                    'action' => 'wait',
                    'reason' => 'Awaiting approval.',
                ],
            ]),
        ],
        overrides: [
            'id' => 'run-lock-resume-fail',
            'status' => 'waiting',
            'current_step_id' => 'draft',
            'wait' => WaitStateData::from([
                'wait_type' => 'approval',
                'resume_token' => 'resume-abc',
            ])->toArray(),
        ],
    );

    $thrower = new ResumeRetryThrowingLock;
    $this->app->instance(RunLockProvider::class, $thrower);
    $this->app->forgetInstance(Conductor::class);

    $this->postJson("/api/conductor/runs/{$run->id}/resume", [
        'resume_token' => 'resume-abc',
        'payload' => [],
    ])->assertStatus(423)
        ->assertJsonPath('message', 'Run is currently locked by another request.');

    expect($thrower->calls)->toBe(1);
});

it('it wraps retryRun in a lock', function (): void {
    $run = storeEndpointRun(
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'boom',
            ]),
        ],
        overrides: [
            'id' => 'run-lock-retry',
            'status' => 'failed',
            'current_step_id' => 'draft',
        ],
    );

    $recorder = new ResumeRetryRecordingLock;
    $this->app->instance(RunLockProvider::class, $recorder);
    $this->app->forgetInstance(Conductor::class);

    $this->postJson("/api/conductor/runs/{$run->id}/retry", [
        'revision' => 1,
    ])->assertOk();

    expect($recorder->lockedRuns)->toContain($run->id);
});

it('it returns 423 from /retry when lock fails', function (): void {
    $run = storeEndpointRun(
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'boom',
            ]),
        ],
        overrides: [
            'id' => 'run-lock-retry-fail',
            'status' => 'failed',
            'current_step_id' => 'draft',
        ],
    );

    $thrower = new ResumeRetryThrowingLock;
    $this->app->instance(RunLockProvider::class, $thrower);
    $this->app->forgetInstance(Conductor::class);

    $this->postJson("/api/conductor/runs/{$run->id}/retry", [
        'revision' => 1,
    ])->assertStatus(423)
        ->assertJsonPath('message', 'Run is currently locked by another request.');

    expect($thrower->calls)->toBe(1);
});

it('it wraps cancelRun in a lock', function (): void {
    $run = storeEndpointRun(
        overrides: [
            'id' => 'run-lock-cancel',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $recorder = new ResumeRetryRecordingLock;
    $this->app->instance(RunLockProvider::class, $recorder);
    $this->app->forgetInstance(Conductor::class);

    $this->postJson("/api/conductor/runs/{$run->id}/cancel", [
        'revision' => 1,
    ])->assertOk();

    expect($recorder->lockedRuns)->toContain($run->id);
});

it('it returns 423 from /cancel when lock fails', function (): void {
    $run = storeEndpointRun(
        overrides: [
            'id' => 'run-lock-cancel-fail',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    $thrower = new ResumeRetryThrowingLock;
    $this->app->instance(RunLockProvider::class, $thrower);
    $this->app->forgetInstance(Conductor::class);

    $this->postJson("/api/conductor/runs/{$run->id}/cancel", [
        'revision' => 1,
    ])->assertStatus(423)
        ->assertJsonPath('message', 'Run is currently locked by another request.');

    expect($thrower->calls)->toBe(1);
});
