<?php

declare(strict_types=1);

use Atlasphp\Atlas\Agent;
use Atlasphp\Atlas\AgentRegistry;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Enums\Provider;
use Atlasphp\Atlas\Providers\Tools\WebFetch;
use Atlasphp\Atlas\Providers\Tools\WebSearch;
use Atlasphp\Atlas\Requests\TextRequest;
use Atlasphp\Atlas\Responses\Usage;
use Atlasphp\Atlas\Testing\TextResponseFake;
use Atlasphp\Atlas\Tools\ToolDefinition;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Execution\AtlasStepExecutor;

class AtlasStepExecutorToolsTestAgent extends Agent
{
    public function key(): string
    {
        return 'tools-executor-agent';
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
    app(AgentRegistry::class)->register(AtlasStepExecutorToolsTestAgent::class);
    config()->set('conductor.tools.namespace', 'Entrepeneur4lyf\\LaravelConductor\\Tests\\Fixtures\\Tools');
    config()->set('conductor.tools.map', []);
});

it('forwards step-declared tools to Atlas via withTools()', function (): void {
    $fake = Atlas::fake([
        TextResponseFake::make()->withText('ok')->withUsage(new Usage(1, 1)),
    ]);

    $executor = app(WorkflowStepExecutor::class);
    expect($executor)->toBeInstanceOf(AtlasStepExecutor::class);

    $input = StepInputData::from([
        'step_id' => 'draft',
        'run_id' => 'run-tools-integration',
        'rendered_prompt' => 'Do something with the tool.',
        'payload' => [],
        'meta' => [
            'tools' => ['stock_snapshot'],
        ],
    ]);

    $executor->execute('tools-executor-agent', $input);

    $recorded = $fake->recorded();

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]->request)->toBeInstanceOf(TextRequest::class);

    $tools = $recorded[0]->request->tools;

    // Atlas resolves the class string via the container, calls
    // toDefinition(), and stores a ToolDefinition on the request.
    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(ToolDefinition::class)
        ->and($tools[0]->name)->toBe('stock_snapshot')
        ->and($tools[0]->description)->toBe('Returns a fake stock snapshot for testing.');
});

it('forwards step-declared provider_tools to Atlas via withProviderTools()', function (): void {
    $fake = Atlas::fake([
        TextResponseFake::make()->withText('ok')->withUsage(new Usage(1, 1)),
    ]);

    $executor = app(WorkflowStepExecutor::class);

    $input = StepInputData::from([
        'step_id' => 'draft',
        'run_id' => 'run-provider-tools-integration',
        'rendered_prompt' => 'Do something.',
        'payload' => [],
        'meta' => [
            'provider_tools' => [
                ['type' => 'web_search', 'max_results' => 5],
                'web_fetch',
            ],
        ],
    ]);

    $executor->execute('tools-executor-agent', $input);

    $recorded = $fake->recorded();

    expect($recorded)->toHaveCount(1);

    $providerTools = $recorded[0]->request->providerTools;

    expect($providerTools)->toHaveCount(2)
        ->and($providerTools[0])->toBeInstanceOf(WebSearch::class)
        ->and($providerTools[0]->config())->toBe(['max_results' => 5])
        ->and($providerTools[1])->toBeInstanceOf(WebFetch::class);
});

it('forwards both tools and provider_tools in the same request', function (): void {
    $fake = Atlas::fake([
        TextResponseFake::make()->withText('ok')->withUsage(new Usage(1, 1)),
    ]);

    $executor = app(WorkflowStepExecutor::class);

    $input = StepInputData::from([
        'step_id' => 'draft',
        'run_id' => 'run-tools-mixed',
        'rendered_prompt' => 'Combined.',
        'payload' => [],
        'meta' => [
            'tools' => ['stock_snapshot'],
            'provider_tools' => ['web_search'],
        ],
    ]);

    $executor->execute('tools-executor-agent', $input);

    $recorded = $fake->recorded();

    expect($recorded[0]->request->tools)->toHaveCount(1)
        ->and($recorded[0]->request->tools[0])->toBeInstanceOf(ToolDefinition::class)
        ->and($recorded[0]->request->tools[0]->name)->toBe('stock_snapshot')
        ->and($recorded[0]->request->providerTools)->toHaveCount(1)
        ->and($recorded[0]->request->providerTools[0])->toBeInstanceOf(WebSearch::class);
});

it('does not call withTools when meta has no tools', function (): void {
    $fake = Atlas::fake([
        TextResponseFake::make()->withText('ok')->withUsage(new Usage(1, 1)),
    ]);

    $executor = app(WorkflowStepExecutor::class);

    $input = StepInputData::from([
        'step_id' => 'draft',
        'run_id' => 'run-tools-empty',
        'rendered_prompt' => 'Plain.',
        'payload' => [],
        'meta' => [],
    ]);

    $executor->execute('tools-executor-agent', $input);

    $recorded = $fake->recorded();

    expect($recorded[0]->request->tools)->toBe([])
        ->and($recorded[0]->request->providerTools)->toBe([]);
});

it('propagates a resolution error from the executor when a tool cannot be resolved', function (): void {
    Atlas::fake([
        TextResponseFake::make()->withText('ok')->withUsage(new Usage(1, 1)),
    ]);

    $executor = app(WorkflowStepExecutor::class);

    $input = StepInputData::from([
        'step_id' => 'draft',
        'run_id' => 'run-tools-missing',
        'rendered_prompt' => 'Broken.',
        'payload' => [],
        'meta' => [
            'tools' => ['this_tool_does_not_exist_anywhere'],
        ],
    ]);

    expect(fn () => $executor->execute('tools-executor-agent', $input))
        ->toThrow(RuntimeException::class);
});
