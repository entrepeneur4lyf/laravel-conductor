<?php

declare(strict_types=1);

use Atlasphp\Atlas\Agent;
use Atlasphp\Atlas\AgentRegistry;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Enums\Provider;
use Atlasphp\Atlas\Responses\Usage;
use Atlasphp\Atlas\Testing\StructuredResponseFake;

class ContinueWorkflowTestAgent extends Agent
{
    public function key(): string
    {
        return 'continue-workflow-agent';
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

it('continues a run through structured execution and no-ops after terminal completion', function (): void {
    app(AgentRegistry::class)->register(ContinueWorkflowTestAgent::class);

    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'conductor-continue-'.bin2hex(random_bytes(5));
    $promptDirectory = $directory.DIRECTORY_SEPARATOR.'prompts';
    $schemaDirectory = $directory.DIRECTORY_SEPARATOR.'schemas';

    mkdir($promptDirectory, 0777, true);
    mkdir($schemaDirectory, 0777, true);

    file_put_contents($directory.DIRECTORY_SEPARATOR.'continue-workflow.yaml', <<<'YAML'
name: continue-workflow
version: 1
description: Continue endpoint test workflow
steps:
  - id: draft
    agent: continue-workflow-agent
    prompt_template: prompts/draft.md.j2
    output_schema: "@schemas/draft-output.json"
    retries: 1
    timeout: 60
    on_success: complete
failure_handlers: []
YAML);

    file_put_contents($promptDirectory.DIRECTORY_SEPARATOR.'draft.md.j2', 'Write a draft about {{ topic }}.');
    file_put_contents($schemaDirectory.DIRECTORY_SEPARATOR.'draft-output.json', json_encode([
        'type' => 'object',
        'required' => ['headline'],
        'properties' => [
            'headline' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ], JSON_THROW_ON_ERROR));

    config()->set('conductor.definitions.paths', [$directory]);

    $fake = Atlas::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'headline' => 'Laravel Conductor',
            ])
            ->withUsage(new Usage(4, 6)),
    ]);

    $start = $this->postJson('/api/conductor/start', [
        'workflow' => 'continue-workflow',
        'input' => [
            'topic' => 'Laravel Conductor',
        ],
    ]);

    $runId = (string) $start->json('data.id');

    $continued = $this->postJson("/api/conductor/runs/{$runId}/continue");

    $continued->assertOk()
        ->assertJsonPath('decision.action', 'complete')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.current_step_id', null)
        ->assertJsonPath('data.output.headline', 'Laravel Conductor')
        ->assertJsonPath('data.steps.0.status', 'completed')
        ->assertJsonPath('data.steps.0.output.metadata.response_type', 'structured');

    $terminalRevision = $continued->json('data.revision');

    $noop = $this->postJson("/api/conductor/runs/{$runId}/continue");

    $noop->assertOk()
        ->assertJsonPath('decision.action', 'noop')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.revision', $terminalRevision);

    expect($fake->recorded())->toHaveCount(1);
});

it('appends a pending retry attempt without executing it until continue is called', function (): void {
    app(AgentRegistry::class)->register(ContinueWorkflowTestAgent::class);

    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'conductor-retry-'.bin2hex(random_bytes(5));
    $promptDirectory = $directory.DIRECTORY_SEPARATOR.'prompts';
    $schemaDirectory = $directory.DIRECTORY_SEPARATOR.'schemas';

    mkdir($promptDirectory, 0777, true);
    mkdir($schemaDirectory, 0777, true);

    file_put_contents($directory.DIRECTORY_SEPARATOR.'retry-workflow.yaml', <<<'YAML'
name: retry-workflow
version: 1
steps:
  - id: draft
    agent: continue-workflow-agent
    prompt_template: prompts/draft.md.j2
    output_schema: "@schemas/draft-output.json"
    retries: 2
    on_success: complete
failure_handlers: []
YAML);

    file_put_contents($promptDirectory.DIRECTORY_SEPARATOR.'draft.md.j2', 'Write a draft about {{ topic }}.');
    file_put_contents($schemaDirectory.DIRECTORY_SEPARATOR.'draft-output.json', json_encode([
        'type' => 'object',
        'required' => ['headline'],
        'properties' => [
            'headline' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ], JSON_THROW_ON_ERROR));

    config()->set('conductor.definitions.paths', [$directory]);

    $fake = Atlas::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'headline' => 'Recovered draft',
            ])
            ->withUsage(new Usage(5, 7)),
    ]);

    $started = $this->postJson('/api/conductor/start', [
        'workflow' => 'retry-workflow',
        'input' => [
            'topic' => 'Retry semantics',
        ],
    ])->json('data');

    $runId = (string) $started['id'];

    $store = app(\Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore::class);
    $run = $store->get($runId);
    expect($run)->not->toBeNull();

    $failed = $store->save(
        \Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData::from([
            ...$run?->toArray(),
            'revision' => 2,
            'status' => 'failed',
            'steps' => [
                \Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData::from([
                    ...$run?->steps[0]->toArray(),
                    'status' => 'failed',
                    'error' => 'manual_failure',
                ])->toArray(),
            ],
        ]),
        1,
    );

    $retry = $this->postJson("/api/conductor/runs/{$runId}/retry", [
        'revision' => $failed->revision,
    ]);

    $retry->assertOk()
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.revision', 3)
        ->assertJsonPath('data.steps.1.status', 'pending')
        ->assertJsonPath('data.steps.1.attempt', 2);

    expect($fake->recorded())->toHaveCount(0);

    $continued = $this->postJson("/api/conductor/runs/{$runId}/continue");

    $continued->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('decision.action', 'complete');

    expect($fake->recorded())->toHaveCount(1);
});
