<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WaitStateData;

it('resumes a waiting run when the resume token is valid', function (): void {
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
            'id' => 'run-resume',
            'status' => 'waiting',
            'current_step_id' => 'draft',
            'wait' => WaitStateData::from([
                'wait_type' => 'approval',
                'resume_token' => 'resume-123',
            ])->toArray(),
        ],
    );

    $this->postJson("/api/conductor/runs/{$run->id}/resume", [
        'resume_token' => 'resume-123',
        'payload' => [
            'headline' => 'Approved headline',
        ],
    ])->assertOk()
        ->assertJsonPath('data.id', 'run-resume')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.current_step_id', null)
        ->assertJsonPath('data.output.headline', 'Approved headline')
        ->assertJsonPath('data.wait', null);
});

it('rejects resume requests when the resume token is invalid', function (): void {
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
            'id' => 'run-resume-invalid',
            'status' => 'waiting',
            'current_step_id' => 'draft',
            'wait' => WaitStateData::from([
                'wait_type' => 'approval',
                'resume_token' => 'resume-123',
            ])->toArray(),
        ],
    );

    $this->postJson("/api/conductor/runs/{$run->id}/resume", [
        'resume_token' => 'wrong-token',
        'payload' => [
            'headline' => 'Approved headline',
        ],
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Invalid resume token.');
});
