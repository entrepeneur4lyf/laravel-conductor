<?php

declare(strict_types=1);

use Atlasphp\Atlas\Agent;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Enums\Provider;
use Atlasphp\Atlas\Responses\Usage;
use Atlasphp\Atlas\Schema\Schema;
use Atlasphp\Atlas\Requests\TextRequest;
use Atlasphp\Atlas\Testing\StructuredResponseFake;
use Atlasphp\Atlas\Testing\TextResponseFake;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Execution\AtlasStepExecutor;
use Atlasphp\Atlas\AgentRegistry;

class StructuredExecutionTestAgent extends Agent
{
    public function key(): string
    {
        return 'structured-execution';
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

it('uses structured output when a resolved schema path is present', function (): void {
    registerStructuredExecutionTestAgent();

    $schemaPath = tempnam(sys_get_temp_dir(), 'conductor-schema-');
    expect($schemaPath)->not->toBeFalse();

    $schemaPath .= '.json';
    file_put_contents($schemaPath, json_encode([
        'type' => 'object',
        'properties' => [
            'headline' => ['type' => 'string'],
            'summary' => ['type' => 'string'],
        ],
        'required' => ['headline', 'summary'],
        'additionalProperties' => false,
    ], JSON_THROW_ON_ERROR));

    $fake = Atlas::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'headline' => 'Conductor',
                'summary' => 'Workflow orchestration for Laravel.',
            ])
            ->withUsage(new Usage(7, 11)),
    ]);

    $executor = app(WorkflowStepExecutor::class);
    expect($executor)->toBeInstanceOf(AtlasStepExecutor::class);

    $input = StepInputData::from([
        'step_id' => 'draft',
        'run_id' => 'run-01',
        'rendered_prompt' => 'Write the article.',
        'payload' => [
            'topic' => 'Laravel Conductor',
        ],
        'meta' => [
            'output_schema_path' => $schemaPath,
        ],
    ]);

    $output = $executor->execute('structured-execution', $input);

    expect($output)->toBeInstanceOf(StepOutputData::class)
        ->and($output->step_id)->toBe('draft')
        ->and($output->run_id)->toBe('run-01')
        ->and($output->status)->toBe('completed')
        ->and($output->payload)->toBe([
            'headline' => 'Conductor',
            'summary' => 'Workflow orchestration for Laravel.',
        ])
        ->and($output->metadata['usage'])->toBe([
            'input_tokens' => 7,
            'output_tokens' => 11,
        ])
        ->and($output->metadata['tokens_used'])->toBe(18);

    $recorded = $fake->recorded();

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]->method)->toBe('structured')
        ->and($recorded[0]->request)->toBeInstanceOf(TextRequest::class)
        ->and($recorded[0]->request->message)->toBe('Write the article.')
        ->and($recorded[0]->request->schema)->toBeInstanceOf(Schema::class)
        ->and($recorded[0]->request->schema->toArray())->toBe([
            'type' => 'object',
            'properties' => [
                'headline' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
            ],
            'required' => ['headline', 'summary'],
            'additionalProperties' => false,
        ]);

    unlink($schemaPath);
});

it('falls back to text output when no schema path is provided', function (): void {
    registerStructuredExecutionTestAgent();

    $fake = Atlas::fake([
        TextResponseFake::make()
            ->withText('Plain response')
            ->withUsage(new Usage(3, 4)),
    ]);

    $executor = app(WorkflowStepExecutor::class);

    $input = StepInputData::from([
        'step_id' => 'draft',
        'run_id' => 'run-02',
        'rendered_prompt' => 'Summarize the result.',
        'payload' => [
            'topic' => 'Laravel Conductor',
        ],
        'meta' => [],
    ]);

    $output = $executor->execute('structured-execution', $input);

    expect($output)->toBeInstanceOf(StepOutputData::class)
        ->and($output->payload)->toBe([
            'text' => 'Plain response',
        ])
        ->and($output->metadata['usage'])->toBe([
            'input_tokens' => 3,
            'output_tokens' => 4,
        ])
        ->and($output->metadata['tokens_used'])->toBe(7);

    $recorded = $fake->recorded();

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]->method)->toBe('text')
        ->and($recorded[0]->request)->toBeInstanceOf(TextRequest::class)
        ->and($recorded[0]->request->schema)->toBeNull()
        ->and($recorded[0]->request->message)->toBe('Summarize the result.');
});

function registerStructuredExecutionTestAgent(): void
{
    app(AgentRegistry::class)->register(StructuredExecutionTestAgent::class);
}
