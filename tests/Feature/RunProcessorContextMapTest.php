<?php

declare(strict_types=1);

use Atlasphp\Atlas\Agent;
use Atlasphp\Atlas\AgentRegistry;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Enums\Provider;
use Atlasphp\Atlas\Testing\TextResponseFake;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\RunProcessor;

class ContextMapTestAgent extends Agent
{
    public function key(): string
    {
        return 'context-map-agent';
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

beforeEach(function (): void {
    app(AgentRegistry::class)->register(ContextMapTestAgent::class);
});

it('resolves context_map values into the rendered prompt context', function (): void {
    $fake = Atlas::fake([
        TextResponseFake::make()->withText('Rendered'),
    ]);

    $run = WorkflowRunStateData::from([
        'id' => 'run-context-map',
        'workflow' => 'context-map-workflow',
        'workflow_version' => 1,
        'revision' => 1,
        'status' => 'running',
        'current_step_id' => 'draft',
        'input' => [
            'topic' => 'Laravel Conductor',
        ],
        'output' => [
            'summary' => 'A prior structured summary.',
        ],
        'context' => [
            'source' => 'rss',
        ],
        'snapshot' => CompiledWorkflowData::from([
            'name' => 'context-map-workflow',
            'version' => 1,
            'compiled_at' => '2026-04-06T12:00:00Z',
            'source_hash' => 'sha256:context-map',
            'steps' => [
                StepDefinitionData::from([
                    'id' => 'draft',
                    'agent' => 'context-map-agent',
                    'prompt_template_contents' => 'Topic={{ mapped_topic }}|Source={{ source_name }}|Summary={{ prior_summary }}|Missing={{ missing_value|default("null") }}',
                    'context_map' => [
                        'mapped_topic' => 'input.topic',
                        'source_name' => 'context.source',
                        'prior_summary' => 'output.summary',
                        'missing_value' => 'output.missing',
                    ],
                    'on_success' => 'complete',
                ])->toArray(),
            ],
            'failure_handlers' => [],
            'defaults' => [],
        ])->toArray(),
        'steps' => [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'pending',
                'attempt' => 1,
            ])->toArray(),
        ],
    ]);

    $stored = app(WorkflowStateStore::class)->store($run);

    app(RunProcessor::class)->continueRun($stored->id);

    $recorded = $fake->recorded();

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]->request->message)->toBe('Topic=Laravel Conductor|Source=rss|Summary=A prior structured summary.|Missing=null');
});
