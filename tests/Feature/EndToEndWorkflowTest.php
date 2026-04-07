<?php

declare(strict_types=1);

use Atlasphp\Atlas\Agent;
use Atlasphp\Atlas\AgentRegistry;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Enums\Provider;
use Atlasphp\Atlas\Responses\Usage;
use Atlasphp\Atlas\Testing\StructuredResponseFake;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;
use Entrepeneur4lyf\LaravelConductor\Engine\TemplateRenderer;

it('proves the end-to-end workflow runtime semantics', function (): void {
    config()->set('conductor.definitions.paths', [workflowFixtureDirectory()]);

    registerEndToEndResearchAgent();

    $fake = Atlas::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'summary' => 'Laravel Conductor orchestrates Atlas-powered workflows.',
            ])
            ->withUsage(new Usage(9, 14)),
    ]);

    $startResponse = $this->postJson('/api/conductor/start', [
        'workflow' => 'content-pipeline-e2e',
        'input' => [
            'topic' => 'Laravel Conductor',
        ],
    ]);

    $runId = $startResponse->json('data.id');

    $startResponse->assertCreated()
        ->assertJsonPath('data.workflow', 'content-pipeline-e2e')
        ->assertJsonPath('data.status', 'initializing')
        ->assertJsonPath('data.revision', 1)
        ->assertJsonPath('data.current_step_id', 'research');

    $run = app(WorkflowStateStore::class)->get($runId);
    $fixtureRoot = workflowFixtureDirectory();

    expect($run)->not->toBeNull()
        ->and($run?->revision)->toBe(1)
        ->and($run?->snapshot->name)->toBe('content-pipeline-e2e')
        ->and($run?->snapshot->source_hash)->toStartWith('sha256:')
        ->and($run?->snapshot->compiled_at)->not->toBeEmpty();

    $research = snapshotStep($run, 'research');

    expect($research->prompt_template)->toBe('prompts/research.md.j2')
        ->and($research->prompt_template_path)->toBe($fixtureRoot.'/prompts/research.md.j2')
        ->and($research->prompt_template_path)->not->toBe($research->prompt_template)
        ->and($research->output_schema)->toBe('@schemas/research-output.json')
        ->and($research->output_schema_path)->toBe($fixtureRoot.'/schemas/research-output.json')
        ->and($research->output_schema_path)->not->toBe($research->output_schema);

    $input = StepInputData::from([
        'step_id' => 'research',
        'run_id' => $runId,
        'rendered_prompt' => app(TemplateRenderer::class)->renderContents(
            $research->prompt_template_contents ?? '',
            [
                'topic' => $run?->input['topic'],
            ],
            $research->prompt_template_path,
        ),
        'payload' => $run?->input ?? [],
        'meta' => [
            'output_schema_path' => $research->output_schema_path,
        ],
    ]);

    $output = app(WorkflowStepExecutor::class)->execute($research->agent, $input);

    expect($output)->toBeInstanceOf(StepOutputData::class)
        ->and($output->status)->toBe('completed')
        ->and($output->payload)->toBe([
            'summary' => 'Laravel Conductor orchestrates Atlas-powered workflows.',
        ])
        ->and($output->metadata['response_type'])->toBe('structured');

    $recorded = $fake->recorded();

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]->method)->toBe('structured');

    $completedRun = persistCompletedStep($run, 'research', $input, $output);

    expect($completedRun->revision)->toBe(2)
        ->and(latestStep($completedRun, 'research')?->status)->toBe('completed')
        ->and(latestStep($completedRun, 'research')?->input?->rendered_prompt)->toStartWith('Research the topic and return a structured summary.');

    $advanceDecision = app(Supervisor::class)->evaluate($completedRun->id, 'research');
    $advancedRun = app(WorkflowStateStore::class)->get($completedRun->id);

    expect($advanceDecision->action)->toBe('advance')
        ->and($advanceDecision->next_step_id)->toBe('approval')
        ->and($advancedRun)->not->toBeNull()
        ->and($advancedRun?->revision)->toBe(3)
        ->and($advancedRun?->status)->toBe('running')
        ->and($advancedRun?->current_step_id)->toBe('approval')
        ->and(latestStep($advancedRun, 'approval')?->status)->toBe('pending');

    $staleDecision = app(Supervisor::class)->evaluate($advancedRun->id, 'research');

    expect($staleDecision->action)->toBe('noop')
        ->and($staleDecision->reason)->toBe('Current step no longer matches the evaluation target.');

    $waitDecision = app(Supervisor::class)->evaluate($advancedRun->id, 'approval');
    $waitingRun = app(WorkflowStateStore::class)->get($advancedRun->id);

    expect($waitDecision->action)->toBe('wait')
        ->and($waitingRun)->not->toBeNull()
        ->and($waitingRun?->revision)->toBe(4)
        ->and($waitingRun?->status)->toBe('waiting')
        ->and($waitingRun?->current_step_id)->toBe('approval')
        ->and($waitingRun?->wait?->resume_token)->not->toBeEmpty();

    $resumeToken = $waitingRun?->wait?->resume_token;

    $resumeResponse = $this->postJson("/api/conductor/runs/{$waitingRun?->id}/resume", [
        'resume_token' => $resumeToken,
        'payload' => [
            'final_summary' => 'Approved summary for publication.',
        ],
    ]);

    $resumeResponse->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.current_step_id', null)
        ->assertJsonPath('data.revision', 6)
        ->assertJsonPath('data.output.final_summary', 'Approved summary for publication.')
        ->assertJsonPath('data.wait', null)
        ->assertJsonPath('decision.action', 'complete');

    $terminalRun = app(WorkflowStateStore::class)->get($runId);

    expect($terminalRun)->not->toBeNull()
        ->and($terminalRun?->status)->toBe('completed')
        ->and($terminalRun?->revision)->toBe(6)
        ->and($terminalRun?->timeline)->toHaveCount(4)
        ->and(array_map(static fn ($entry) => $entry->type, $terminalRun?->timeline ?? []))
        ->toBe([
            'workflow_started',
            'step_waiting',
            'workflow_resumed',
            'workflow_completed',
        ]);

    $this->postJson("/api/conductor/runs/{$runId}/cancel", [
        'revision' => 6,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Run is not eligible for cancellation.');

    $terminalDecision = app(Supervisor::class)->evaluate($runId, 'approval');

    expect($terminalDecision->action)->toBe('noop')
        ->and($terminalDecision->reason)->toBe('Run is terminal.');
});

class EndToEndResearchAgent extends Agent
{
    public function key(): string
    {
        return 'research-agent';
    }

    public function provider(): Provider|string|null
    {
        return Provider::OpenAI;
    }

    public function model(): ?string
    {
        return 'gpt-4o-mini';
    }
}

function registerEndToEndResearchAgent(): void
{
    app(AgentRegistry::class)->register(EndToEndResearchAgent::class);
}

function snapshotStep(?WorkflowRunStateData $run, string $stepId): StepDefinitionData
{
    expect($run)->not->toBeNull();

    foreach ($run?->snapshot->steps ?? [] as $step) {
        if ($step->id === $stepId) {
            return $step;
        }
    }

    throw new RuntimeException(sprintf('Compiled step [%s] was not found.', $stepId));
}

function latestStep(WorkflowRunStateData $run, string $stepId): ?StepExecutionStateData
{
    $steps = array_values(array_filter(
        $run->steps,
        static fn (StepExecutionStateData $step): bool => $step->step_definition_id === $stepId,
    ));

    if ($steps === []) {
        return null;
    }

    return $steps[array_key_last($steps)];
}

function persistCompletedStep(
    ?WorkflowRunStateData $run,
    string $stepId,
    StepInputData $input,
    StepOutputData $output,
): WorkflowRunStateData {
    expect($run)->not->toBeNull();

    $steps = array_map(
        static function (StepExecutionStateData $step) use ($stepId, $input, $output): array {
            if ($step->step_definition_id !== $stepId) {
                return $step->toArray();
            }

            return StepExecutionStateData::from([
                ...$step->toArray(),
                'status' => 'completed',
                'input' => $input->toArray(),
                'output' => $output->toArray(),
                'error' => null,
                'completed_at' => now('UTC')->toIso8601String(),
            ])->toArray();
        },
        $run?->steps ?? [],
    );

    return app(WorkflowStateStore::class)->save(
        WorkflowRunStateData::from([
            ...$run?->toArray(),
            'revision' => ($run?->revision ?? 0) + 1,
            'status' => 'running',
            'steps' => $steps,
        ]),
        $run?->revision ?? 0,
    );
}
