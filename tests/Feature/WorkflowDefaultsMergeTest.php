<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Definitions\WorkflowCompiler;
use Entrepeneur4lyf\LaravelConductor\Definitions\YamlWorkflowDefinitionRepository;

/**
 * Active assertions for F8 — workflow root `defaults` are merged into
 * each step at load time when the step does not declare the same field
 * explicitly. Identity fields (id, agent, prompt_template,
 * output_schema) are NOT merge-eligible and the loader rejects them
 * with a clear error message.
 */
function writeDefaultsMergeWorkflow(string $yaml): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'conductor-defaults-'.bin2hex(random_bytes(5));
    mkdir($directory, 0777, true);
    file_put_contents($directory.DIRECTORY_SEPARATOR.'defaults.yaml', $yaml);
    config()->set('conductor.definitions.paths', [$directory]);

    return $directory;
}

it('merges defaults.retries into a step that does not declare retries', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
description: Defaults merge test
defaults:
  retries: 5
steps:
  - id: draft
    agent: writer
    on_success: complete
YAML);

    $loaded = app(YamlWorkflowDefinitionRepository::class)->load('defaults');
    $compiled = app(WorkflowCompiler::class)->compile($loaded);

    expect($compiled->steps[0]->retries)->toBe(5)
        ->and($compiled->defaults['retries'])->toBe(5);
});

it('merges defaults.timeout into a step that does not declare timeout', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
defaults:
  timeout: 30
steps:
  - id: draft
    agent: writer
    on_success: complete
YAML);

    $loaded = app(YamlWorkflowDefinitionRepository::class)->load('defaults');
    $compiled = app(WorkflowCompiler::class)->compile($loaded);

    expect($compiled->steps[0]->timeout)->toBe(30);
});

it('does not override step values when both step and defaults specify the field', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
defaults:
  retries: 5
  timeout: 30
steps:
  - id: draft
    agent: writer
    retries: 1
    timeout: 200
    on_success: complete
YAML);

    $loaded = app(YamlWorkflowDefinitionRepository::class)->load('defaults');
    $compiled = app(WorkflowCompiler::class)->compile($loaded);

    // The step's explicit values win over defaults.
    expect($compiled->steps[0]->retries)->toBe(1)
        ->and($compiled->steps[0]->timeout)->toBe(200);
});

it('merges defaults across multiple steps independently', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
defaults:
  retries: 3
steps:
  - id: draft
    agent: writer
    on_success: review
  - id: review
    agent: reviewer
    retries: 0
    on_success: complete
YAML);

    $loaded = app(YamlWorkflowDefinitionRepository::class)->load('defaults');
    $compiled = app(WorkflowCompiler::class)->compile($loaded);

    expect($compiled->steps[0]->retries)->toBe(3) // inherited
        ->and($compiled->steps[1]->retries)->toBe(0); // explicit
});

it('merges defaults.tools into a step that does not declare tools', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
defaults:
  tools:
    - shared_tool_a
    - shared_tool_b
steps:
  - id: draft
    agent: writer
    on_success: complete
YAML);

    $loaded = app(YamlWorkflowDefinitionRepository::class)->load('defaults');
    $compiled = app(WorkflowCompiler::class)->compile($loaded);

    expect($compiled->steps[0]->tools)->toBe(['shared_tool_a', 'shared_tool_b']);
});

it('replaces (does not deep-merge) step.tools when both are present', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
defaults:
  tools:
    - shared_tool
steps:
  - id: draft
    agent: writer
    tools:
      - step_specific_tool
    on_success: complete
YAML);

    $loaded = app(YamlWorkflowDefinitionRepository::class)->load('defaults');
    $compiled = app(WorkflowCompiler::class)->compile($loaded);

    // Replace, not append. The step's explicit tools list wins entirely.
    expect($compiled->steps[0]->tools)->toBe(['step_specific_tool']);
});

it('rejects an identity field (agent) declared in defaults', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
defaults:
  agent: should-not-be-allowed
steps:
  - id: draft
    agent: writer
    on_success: complete
YAML);

    expect(fn () => app(YamlWorkflowDefinitionRepository::class)->load('defaults'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an identity field (prompt_template) declared in defaults', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
defaults:
  prompt_template: prompts/should-not-be-allowed.md.j2
steps:
  - id: draft
    agent: writer
    on_success: complete
YAML);

    expect(fn () => app(YamlWorkflowDefinitionRepository::class)->load('defaults'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an identity field (id) declared in defaults', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
defaults:
  id: collision
steps:
  - id: draft
    agent: writer
    on_success: complete
YAML);

    expect(fn () => app(YamlWorkflowDefinitionRepository::class)->load('defaults'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an identity field (output_schema) declared in defaults', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
defaults:
  output_schema: '@schemas/should-not-be-allowed.json'
steps:
  - id: draft
    agent: writer
    on_success: complete
YAML);

    expect(fn () => app(YamlWorkflowDefinitionRepository::class)->load('defaults'))
        ->toThrow(InvalidArgumentException::class);
});

it('leaves step fields untouched when the defaults block is empty', function (): void {
    writeDefaultsMergeWorkflow(<<<'YAML'
name: defaults-merge
version: 1
defaults: {}
steps:
  - id: draft
    agent: writer
    on_success: complete
YAML);

    $loaded = app(YamlWorkflowDefinitionRepository::class)->load('defaults');
    $compiled = app(WorkflowCompiler::class)->compile($loaded);

    // Falls back to DTO defaults.
    expect($compiled->steps[0]->retries)->toBe(0)
        ->and($compiled->steps[0]->timeout)->toBe(120);
});
