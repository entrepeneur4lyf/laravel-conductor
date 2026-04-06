<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Entrepeneur4lyf\LaravelConductor\Contracts\DefinitionRepository;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Definitions\LoadedWorkflowDefinition;
use Entrepeneur4lyf\LaravelConductor\Definitions\WorkflowCompiler;
use Entrepeneur4lyf\LaravelConductor\Definitions\WorkflowDefinitionValidator;
use Entrepeneur4lyf\LaravelConductor\Engine\SchemaValidator;
use Entrepeneur4lyf\LaravelConductor\Engine\TemplateRenderer;

it('loads authored yaml definitions and compiles a frozen snapshot with resolved asset paths', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $loadedDefinition = app(DefinitionRepository::class)->load($sourcePath);

    expect($loadedDefinition)->toBeInstanceOf(LoadedWorkflowDefinition::class)
        ->and($loadedDefinition->sourcePath)->toBe($sourcePath)
        ->and($loadedDefinition->definition)->toBeInstanceOf(WorkflowDefinitionData::class)
        ->and($loadedDefinition->definition->steps)->toHaveCount(1)
        ->and($loadedDefinition->definition->steps[0]->agent)->toBe('research-agent')
        ->and($loadedDefinition->definition->steps[0]->prompt_template)->toBe('prompts/research.md.j2')
        ->and($loadedDefinition->definition->steps[0]->output_schema)->toBe('@schemas/research-output.json')
        ->and($loadedDefinition->definition->failure_handlers)->toHaveCount(1)
        ->and($loadedDefinition->definition->failure_handlers[0]->prompt_template)->toBe('prompts/research.md.j2');

    app(WorkflowDefinitionValidator::class)->validate($loadedDefinition->definition, $loadedDefinition->sourcePath);

    $compiled = app(WorkflowCompiler::class)->compile($loadedDefinition);

    expect($compiled)->toBeInstanceOf(CompiledWorkflowData::class)
        ->and($compiled->compiled_at)->not->toBeEmpty()
        ->and(CarbonImmutable::parse($compiled->compiled_at)->toIso8601String())->toBe($compiled->compiled_at)
        ->and($compiled->source_hash)->toStartWith('sha256:')
        ->and($compiled->steps[0]->agent)->toBe('research-agent')
        ->and($compiled->steps[0]->prompt_template)->toBe('prompts/research.md.j2')
        ->and($compiled->steps[0]->prompt_template_path)->toBe(testFixturePath('prompts/research.md.j2'))
        ->and($compiled->steps[0]->prompt_template_path)->not->toBe($compiled->steps[0]->prompt_template)
        ->and($compiled->steps[0]->prompt_template_contents)->toBe(file_get_contents(testFixturePath('prompts/research.md.j2')))
        ->and($compiled->steps[0]->output_schema)->toBe('@schemas/research-output.json')
        ->and($compiled->steps[0]->output_schema_path)->toBe(testFixturePath('schemas/research-output.json'))
        ->and($compiled->steps[0]->output_schema_path)->not->toBe($compiled->steps[0]->output_schema)
        ->and($compiled->steps[0]->output_schema_contents)->toBe(file_get_contents(testFixturePath('schemas/research-output.json')))
        ->and($compiled->failure_handlers)->toHaveCount(1)
        ->and($compiled->failure_handlers[0]->match)->toBe('schema_validation_failed')
        ->and($compiled->failure_handlers[0]->prompt_template)->toBe('prompts/research.md.j2')
        ->and($compiled->failure_handlers[0]->prompt_template_path)->toBe(testFixturePath('prompts/research.md.j2'))
        ->and($compiled->failure_handlers[0]->prompt_template_path)->not->toBe($compiled->failure_handlers[0]->prompt_template)
        ->and($compiled->failure_handlers[0]->prompt_template_contents)->toBe(file_get_contents(testFixturePath('prompts/research.md.j2')));
});

it('validates nested associative array payloads through the schema validator', function (): void {
    $schemaPath = writeTemporarySchemaFile([
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'type' => 'object',
        'required' => ['meta'],
        'properties' => [
            'meta' => [
                'type' => 'object',
                'required' => ['status', 'details'],
                'properties' => [
                    'status' => [
                        'type' => 'string',
                    ],
                    'details' => [
                        'type' => 'object',
                        'required' => ['tags', 'counts'],
                        'properties' => [
                            'tags' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                            'counts' => [
                                'type' => 'object',
                                'required' => ['processed'],
                                'properties' => [
                                    'processed' => [
                                        'type' => 'integer',
                                    ],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
        ],
        'additionalProperties' => false,
    ]);

    try {
        app(SchemaValidator::class)->validate([
            'meta' => [
                'status' => 'ready',
                'details' => [
                    'tags' => ['news', 'tech'],
                    'counts' => [
                        'processed' => 2,
                    ],
                ],
            ],
        ], $schemaPath);
    } finally {
        @unlink($schemaPath);
    }

    expect(true)->toBeTrue();
});

it('keeps source hashes stable across identical workflows at different absolute paths', function (): void {
    $firstDirectory = copyWorkflowFixturesToTemp();
    $secondDirectory = copyWorkflowFixturesToTemp();

    $firstSourcePath = $firstDirectory.'/content-pipeline.yaml';
    $secondSourcePath = $secondDirectory.'/content-pipeline.yaml';

    $firstCompiled = app(WorkflowCompiler::class)->compile(
        app(DefinitionRepository::class)->load($firstSourcePath),
    );
    $secondCompiled = app(WorkflowCompiler::class)->compile(
        app(DefinitionRepository::class)->load($secondSourcePath),
    );

    expect($firstCompiled->source_hash)->toBe($secondCompiled->source_hash)
        ->and($firstCompiled->steps[0]->prompt_template_path)->not->toBe($secondCompiled->steps[0]->prompt_template_path)
        ->and($firstCompiled->steps[0]->output_schema_path)->not->toBe($secondCompiled->steps[0]->output_schema_path);
});

it('freezes referenced execution assets into the compiled snapshot and source hash', function (): void {
    $fixtureDirectory = copyWorkflowFixturesToTemp();
    $sourcePath = $fixtureDirectory.'/content-pipeline.yaml';
    $promptPath = $fixtureDirectory.'/prompts/research.md.j2';
    $schemaPath = $fixtureDirectory.'/schemas/research-output.json';

    $originalPrompt = file_get_contents($promptPath);
    $originalSchema = file_get_contents($schemaPath);

    expect($originalPrompt)->not->toBeFalse()
        ->and($originalSchema)->not->toBeFalse();

    $loadedDefinition = app(DefinitionRepository::class)->load($sourcePath);
    $compiled = app(WorkflowCompiler::class)->compile($loadedDefinition);

    file_put_contents($promptPath, "Updated prompt template contents.\n");
    file_put_contents($schemaPath, json_encode([
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'type' => 'object',
        'required' => ['headline'],
        'properties' => [
            'headline' => [
                'type' => 'string',
            ],
        ],
        'additionalProperties' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $recompiled = app(WorkflowCompiler::class)->compile(
        app(DefinitionRepository::class)->load($sourcePath),
    );

    expect($compiled->steps[0]->prompt_template_contents)->toBe($originalPrompt)
        ->and($compiled->steps[0]->output_schema_contents)->toBe($originalSchema)
        ->and($compiled->failure_handlers[0]->prompt_template_contents)->toBe($originalPrompt)
        ->and($compiled->source_hash)->not->toBe($recompiled->source_hash)
        ->and($compiled->steps[0]->prompt_template_path)->toBe($recompiled->steps[0]->prompt_template_path)
        ->and($compiled->steps[0]->output_schema_path)->toBe($recompiled->steps[0]->output_schema_path)
        ->and($recompiled->steps[0]->prompt_template_contents)->toBe(file_get_contents($promptPath))
        ->and($recompiled->steps[0]->output_schema_contents)->toBe(file_get_contents($schemaPath))
        ->and($recompiled->failure_handlers[0]->prompt_template_contents)->toBe(file_get_contents($promptPath));
});

it('loads authored json definitions with agent as the step field name', function (): void {
    $loadedDefinition = app(DefinitionRepository::class)->load(testFixturePath('content-pipeline.json'));

    expect($loadedDefinition)->toBeInstanceOf(LoadedWorkflowDefinition::class)
        ->and($loadedDefinition->definition)->toBeInstanceOf(WorkflowDefinitionData::class)
        ->and($loadedDefinition->definition->name)->toBe('content-pipeline-json')
        ->and($loadedDefinition->definition->steps)->toHaveCount(1)
        ->and($loadedDefinition->definition->steps[0]->agent)->toBe('review-agent');
});

it('rejects scalar json workflow payloads with an invalid argument exception', function (): void {
    $sourcePath = writeTemporaryWorkflowFile('.json', "42\n");

    try {
        expect(fn () => app(DefinitionRepository::class)->load($sourcePath))
            ->toThrow(
                InvalidArgumentException::class,
                sprintf('Workflow definition [%s] must decode to an object payload.', $sourcePath),
            );
    } finally {
        @unlink($sourcePath);
    }
});

it('rejects scalar yaml workflow payloads with an invalid argument exception', function (): void {
    $sourcePath = writeTemporaryWorkflowFile('.yaml', "42\n");

    try {
        expect(fn () => app(DefinitionRepository::class)->load($sourcePath))
            ->toThrow(
                InvalidArgumentException::class,
                sprintf('Workflow definition [%s] must decode to an object payload.', $sourcePath),
            );
    } finally {
        @unlink($sourcePath);
    }
});

it('rejects prompt template paths that escape the workflow directory tree during compilation', function (): void {
    $fixtureDirectory = copyWorkflowFixturesToTemp();
    $sourcePath = $fixtureDirectory.'/content-pipeline.yaml';
    $outsideTemplatePath = writeTemporaryWorkflowFile('.j2', "Outside prompt template.\n");
    $escapedTemplateReference = '../'.basename($outsideTemplatePath);

    $definition = WorkflowDefinitionData::from([
        'name' => 'escaped-prompt-template',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'prompt_template' => $escapedTemplateReference,
            ],
        ],
    ]);

    try {
        expect(fn () => app(WorkflowCompiler::class)->compile($definition, $sourcePath))
            ->toThrow(
                InvalidArgumentException::class,
                sprintf(
                    'Prompt template [%s] could not be resolved from [%s].',
                    $escapedTemplateReference,
                    $sourcePath,
                ),
            );
    } finally {
        @unlink($outsideTemplatePath);
    }
});

it('rejects templates with multiple unsupported twig template references', function (): void {
    $renderer = app(TemplateRenderer::class);

    expect(fn () => $renderer->assertValidSyntaxContents(
        <<<'TWIG'
{% include "partials/one.twig" %}
{% extends "layouts/base.twig" %}
Hello {{ name }}
TWIG,
        'inline-template',
    ))->toThrow(
        InvalidArgumentException::class,
        'Prompt template [inline-template] uses unsupported Twig template references [include, extends].',
    );
});

it('rejects absolute schema paths outside the workflow directory tree during compilation', function (): void {
    $fixtureDirectory = copyWorkflowFixturesToTemp();
    $sourcePath = $fixtureDirectory.'/content-pipeline.yaml';
    $outsideSchemaPath = writeTemporarySchemaFile([
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'type' => 'object',
    ]);

    $definition = WorkflowDefinitionData::from([
        'name' => 'escaped-output-schema',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'output_schema' => $outsideSchemaPath,
            ],
        ],
    ]);

    try {
        expect(fn () => app(WorkflowCompiler::class)->compile($definition, $sourcePath))
            ->toThrow(
                InvalidArgumentException::class,
                sprintf(
                    'Output schema [%s] could not be resolved from [%s].',
                    $outsideSchemaPath,
                    $sourcePath,
                ),
            );
    } finally {
        @unlink($outsideSchemaPath);
    }
});

it('loads named workflow definitions from configured search roots before the cwd fallback', function (): void {
    $originalDefinitionPaths = config('conductor.definitions.paths');
    $fixtureDirectory = copyWorkflowFixturesToTemp();
    $cwdWorkflowPath = getcwd().'/content-pipeline.yaml';
    $cwdWorkflowContents = <<<YAML
name: cwd-content-pipeline
version: 1
steps:
  - id: draft
    agent: cwd-agent
YAML;

    $existingWorkflowContents = file_exists($cwdWorkflowPath) ? file_get_contents($cwdWorkflowPath) : false;
    $cwdWorkflowWasPresent = $existingWorkflowContents !== false;

    if (file_put_contents($cwdWorkflowPath, $cwdWorkflowContents) === false) {
        throw new RuntimeException(sprintf('Unable to write interfering workflow file [%s].', $cwdWorkflowPath));
    }

    config([
        'conductor.definitions.paths' => [
            $fixtureDirectory,
        ],
    ]);

    try {
        $definition = app(DefinitionRepository::class)->load('content-pipeline');
    } finally {
        if ($cwdWorkflowWasPresent) {
            file_put_contents($cwdWorkflowPath, $existingWorkflowContents);
        } else {
            @unlink($cwdWorkflowPath);
        }

        config([
            'conductor.definitions.paths' => $originalDefinitionPaths,
        ]);
    }

    expect($definition)->toBeInstanceOf(LoadedWorkflowDefinition::class)
        ->and($definition->sourcePath)->toBe($fixtureDirectory.'/content-pipeline.yaml')
        ->and($definition->definition)->toBeInstanceOf(WorkflowDefinitionData::class)
        ->and($definition->definition->name)->toBe('content-pipeline')
        ->and($definition->definition->steps)->toHaveCount(1)
        ->and($definition->definition->steps[0]->agent)->toBe('research-agent');
});

it('supports compiling named workflow definitions without a separate resolvePath call', function (): void {
    $originalDefinitionPaths = config('conductor.definitions.paths');
    $fixtureDirectory = copyWorkflowFixturesToTemp();

    config([
        'conductor.definitions.paths' => [
            $fixtureDirectory,
        ],
    ]);

    try {
        $repository = app(DefinitionRepository::class);
        $loadedDefinition = $repository->load('content-pipeline');
        $compiled = app(WorkflowCompiler::class)->compile($loadedDefinition);
    } finally {
        config([
            'conductor.definitions.paths' => $originalDefinitionPaths,
        ]);
    }

    expect($loadedDefinition)->toBeInstanceOf(LoadedWorkflowDefinition::class)
        ->and($loadedDefinition->sourcePath)->toBe($fixtureDirectory.'/content-pipeline.yaml')
        ->and($compiled->steps[0]->prompt_template_path)->toBe($fixtureDirectory.'/prompts/research.md.j2')
        ->and($compiled->steps[0]->output_schema_path)->toBe($fixtureDirectory.'/schemas/research-output.json');
});

it('rejects negative step retries during validation', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $definition = WorkflowDefinitionData::from([
        'name' => 'negative-retries',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'retries' => -1,
            ],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
        ->toThrow(InvalidArgumentException::class, 'Step [draft] must define retries greater than or equal to 0.');
});

it('rejects non-positive step timeout values during validation', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $definition = WorkflowDefinitionData::from([
        'name' => 'non-positive-timeout',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'timeout' => 0,
            ],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
        ->toThrow(InvalidArgumentException::class, 'Step [draft] must define timeout greater than 0 seconds.');
});

it('rejects negative failure handler delays during validation', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $definition = WorkflowDefinitionData::from([
        'name' => 'negative-delay',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
            ],
        ],
        'failure_handlers' => [
            [
                'match' => 'schema_validation_failed',
                'action' => 'wait',
                'delay' => -5,
            ],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
        ->toThrow(InvalidArgumentException::class, 'Failure handler [schema_validation_failed] must define delay greater than or equal to 0.');
});

it('rejects twig include usage during validation', function (): void {
    $fixtureDirectory = copyWorkflowFixturesToTemp();
    $sourcePath = $fixtureDirectory.'/content-pipeline.yaml';
    $templatePath = $fixtureDirectory.'/prompts/include-template.j2';

    if (file_put_contents($templatePath, "{% include 'shared.j2' %}\n") === false) {
        throw new RuntimeException(sprintf('Unable to write unsupported template file [%s].', $templatePath));
    }

    $definition = WorkflowDefinitionData::from([
        'name' => 'twig-include-template',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'prompt_template' => 'prompts/include-template.j2',
            ],
        ],
    ]);

    try {
        expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
            ->toThrow(
                InvalidArgumentException::class,
                sprintf(
                    'Prompt template [%s] uses unsupported Twig template references [include].',
                    $templatePath,
                ),
            );
    } finally {
        @unlink($templatePath);
    }
});

it('rejects twig inheritance usage during validation', function (): void {
    $fixtureDirectory = copyWorkflowFixturesToTemp();
    $sourcePath = $fixtureDirectory.'/content-pipeline.yaml';
    $templatePath = $fixtureDirectory.'/prompts/extends-template.j2';

    if (file_put_contents($templatePath, "{% extends 'base.j2' %}\n") === false) {
        throw new RuntimeException(sprintf('Unable to write unsupported template file [%s].', $templatePath));
    }

    $definition = WorkflowDefinitionData::from([
        'name' => 'twig-extends-template',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'prompt_template' => 'prompts/extends-template.j2',
            ],
        ],
    ]);

    try {
        expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
            ->toThrow(
                InvalidArgumentException::class,
                sprintf(
                    'Prompt template [%s] uses unsupported Twig template references [extends].',
                    $templatePath,
                ),
            );
    } finally {
        @unlink($templatePath);
    }
});

it('rejects schemas with external relative refs during validation', function (): void {
    $fixtureDirectory = copyWorkflowFixturesToTemp();
    $sourcePath = $fixtureDirectory.'/content-pipeline.yaml';
    $schemaPath = $fixtureDirectory.'/schemas/external-ref.json';

    $json = json_encode([
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'type' => 'object',
        'properties' => [
            'meta' => [
                '$ref' => './partials/meta.json#/definitions/meta',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false || file_put_contents($schemaPath, $json."\n") === false) {
        throw new RuntimeException(sprintf('Unable to write schema file [%s].', $schemaPath));
    }

    $definition = WorkflowDefinitionData::from([
        'name' => 'external-schema-ref',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'output_schema' => 'schemas/external-ref.json',
            ],
        ],
    ]);

    try {
        expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
            ->toThrow(
                InvalidArgumentException::class,
                sprintf(
                    'Schema file [%s] uses unsupported external $ref [./partials/meta.json#/definitions/meta].',
                    $schemaPath,
                ),
            );
    } finally {
        @unlink($schemaPath);
    }
});

it('rejects malformed twig prompt templates on workflow steps during compilation', function (): void {
    $fixtureDirectory = copyWorkflowFixturesToTemp();
    $sourcePath = $fixtureDirectory.'/content-pipeline.yaml';
    $templatePath = $fixtureDirectory.'/prompts/invalid-step-template.j2';

    if (file_put_contents($templatePath, "{% if %}\n") === false) {
        throw new RuntimeException(sprintf('Unable to write malformed template file [%s].', $templatePath));
    }

    $definition = WorkflowDefinitionData::from([
        'name' => 'invalid-template-step',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'prompt_template' => $templatePath,
            ],
        ],
    ]);

    try {
        expect(fn () => app(WorkflowCompiler::class)->compile($definition, $sourcePath))
            ->toThrow(
                InvalidArgumentException::class,
                sprintf('Prompt template [%s] contains invalid Twig syntax.', $templatePath),
            );
    } finally {
        @unlink($templatePath);
    }
});

it('rejects malformed twig prompt templates on failure handlers during compilation', function (): void {
    $fixtureDirectory = copyWorkflowFixturesToTemp();
    $sourcePath = $fixtureDirectory.'/content-pipeline.yaml';
    $templatePath = $fixtureDirectory.'/prompts/invalid-failure-template.j2';

    if (file_put_contents($templatePath, "{{ title") === false) {
        throw new RuntimeException(sprintf('Unable to write malformed template file [%s].', $templatePath));
    }

    $definition = WorkflowDefinitionData::from([
        'name' => 'invalid-template-failure-handler',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
            ],
        ],
        'failure_handlers' => [
            [
                'match' => 'schema_validation_failed',
                'action' => 'retry_with_prompt',
                'prompt_template' => $templatePath,
            ],
        ],
    ]);

    try {
        expect(fn () => app(WorkflowCompiler::class)->compile($definition, $sourcePath))
            ->toThrow(
                InvalidArgumentException::class,
                sprintf('Prompt template [%s] contains invalid Twig syntax.', $templatePath),
            );
    } finally {
        @unlink($templatePath);
    }
});

it('rejects duplicate step ids before execution', function (): void {
    $sourcePath = testFixturePath('duplicate-step-ids.yaml');
    $definition = app(DefinitionRepository::class)->load($sourcePath);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition->definition, $definition->sourcePath))
        ->toThrow(InvalidArgumentException::class, 'Duplicate step id [review] in workflow definition.');
});

it('rejects invalid on_success transition targets', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $definition = WorkflowDefinitionData::from([
        'name' => 'invalid-on-success',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'on_success' => 'unknown-step',
            ],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
        ->toThrow(InvalidArgumentException::class, 'Step [draft] has invalid on_success target [unknown-step]. Supported terminal targets: [complete, discard, fail, cancel].');
});

it('rejects invalid on_fail transition targets', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $definition = WorkflowDefinitionData::from([
        'name' => 'invalid-on-fail',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'on_fail' => 'not-a-terminal',
            ],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
        ->toThrow(InvalidArgumentException::class, 'Step [draft] has invalid on_fail target [not-a-terminal]. Supported terminal targets: [complete, discard, fail, cancel].');
});

it('accepts explicit terminal transition targets', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $definition = WorkflowDefinitionData::from([
        'name' => 'terminal-transitions',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'on_success' => 'review',
                'on_fail' => 'fail',
            ],
            [
                'id' => 'review',
                'agent' => 'reviewer',
                'on_success' => 'discard',
                'on_fail' => 'cancel',
            ],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
        ->not->toThrow(InvalidArgumentException::class);
});

it('rejects parallel steps without foreach targets', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $definition = WorkflowDefinitionData::from([
        'name' => 'invalid-parallel-step',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
                'parallel' => true,
            ],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
        ->toThrow(
            InvalidArgumentException::class,
            'Step [draft] cannot enable parallel execution without declaring foreach.',
        );
});

it('rejects unsupported failure handler actions', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $definition = WorkflowDefinitionData::from([
        'name' => 'invalid-failure-handler-action',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
            ],
        ],
        'failure_handlers' => [
            [
                'match' => 'schema_validation_failed',
                'action' => 'complete',
            ],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
        ->toThrow(InvalidArgumentException::class, 'Failure handler [schema_validation_failed] has unsupported action [complete]. Supported actions: [retry, retry_with_prompt, skip, wait, escalate, fail].');
});

it('accepts supported failure handler actions', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $definition = WorkflowDefinitionData::from([
        'name' => 'supported-failure-handler-actions',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
            ],
        ],
        'failure_handlers' => [
            [
                'match' => 'schema_validation_failed',
                'action' => 'retry_with_prompt',
                'prompt_template' => 'prompts/research.md.j2',
            ],
            [
                'match' => 'timeout',
                'action' => 'wait',
            ],
            [
                'match' => 'unknown',
                'action' => 'escalate',
            ],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
        ->not->toThrow(InvalidArgumentException::class);
});

it('rejects retry_with_prompt failure handlers without prompt_template', function (): void {
    $sourcePath = testFixturePath('content-pipeline.yaml');

    $definition = WorkflowDefinitionData::from([
        'name' => 'missing-retry-prompt-template',
        'version' => 1,
        'steps' => [
            [
                'id' => 'draft',
                'agent' => 'writer',
            ],
        ],
        'failure_handlers' => [
            [
                'match' => 'schema_validation_failed',
                'action' => 'retry_with_prompt',
            ],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($definition, $sourcePath))
        ->toThrow(
            InvalidArgumentException::class,
            'Failure handler [schema_validation_failed] with action [retry_with_prompt] must define prompt_template.',
        );
});

it('consumes frozen prompt and schema contents without reading the filesystem again', function (): void {
    $fixtureDirectory = copyWorkflowFixturesToTemp();
    $sourcePath = $fixtureDirectory.'/content-pipeline.yaml';
    $compiled = app(WorkflowCompiler::class)->compile(
        app(DefinitionRepository::class)->load($sourcePath),
    );

    $promptPath = $compiled->steps[0]->prompt_template_path;
    $schemaPath = $compiled->steps[0]->output_schema_path;

    expect($promptPath)->not->toBeNull()
        ->and($schemaPath)->not->toBeNull();

    if (! @unlink($promptPath)) {
        throw new RuntimeException(sprintf('Unable to remove prompt template [%s].', $promptPath));
    }

    if (! @unlink($schemaPath)) {
        throw new RuntimeException(sprintf('Unable to remove schema file [%s].', $schemaPath));
    }

    $rendered = app(TemplateRenderer::class)->renderContents(
        $compiled->steps[0]->prompt_template_contents ?? '',
        displayPath: $promptPath,
    );

    app(SchemaValidator::class)->validateContents(
        [
            'summary' => $rendered,
        ],
        $compiled->steps[0]->output_schema_contents ?? '',
        displayPath: $schemaPath,
    );

    expect($rendered)->toBe($compiled->steps[0]->prompt_template_contents);
});

function testFixturePath(string $relativePath): string
{
    $path = __DIR__.'/../Fixtures/workflows/'.$relativePath;
    $resolved = realpath($path);

    if ($resolved === false) {
        throw new RuntimeException(sprintf('Fixture path [%s] could not be resolved.', $path));
    }

    return $resolved;
}

function testFixtureDirectory(): string
{
    return testFixturePath('.');
}

function writeTemporaryWorkflowFile(string $extension, string $contents): string
{
    $path = sys_get_temp_dir().'/laravel-conductor-workflow-'.bin2hex(random_bytes(8)).$extension;

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException(sprintf('Unable to write temporary workflow file [%s].', $path));
    }

    return $path;
}

/**
 * @param  array<string, mixed>  $schema
 */
function writeTemporarySchemaFile(array $schema): string
{
    $schemaPath = tempnam(sys_get_temp_dir(), 'conductor-schema-');

    if ($schemaPath === false) {
        throw new RuntimeException('Unable to create a temporary schema file.');
    }

    $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('Unable to encode temporary schema JSON.');
    }

    if (file_put_contents($schemaPath, $json."\n") === false) {
        throw new RuntimeException(sprintf('Unable to write temporary schema file [%s].', $schemaPath));
    }

    return $schemaPath;
}

function copyWorkflowFixturesToTemp(): string
{
    $sourceDirectory = testFixturePath('.');
    $temporaryDirectory = sys_get_temp_dir().'/laravel-conductor-workflows-'.bin2hex(random_bytes(8));

    if (! mkdir($temporaryDirectory, 0777, true) && ! is_dir($temporaryDirectory)) {
        throw new RuntimeException(sprintf('Unable to create temporary fixture directory [%s].', $temporaryDirectory));
    }

    copyDirectory($sourceDirectory, $temporaryDirectory);

    return $temporaryDirectory;
}

function copyDirectory(string $sourceDirectory, string $destinationDirectory): void
{
    $entries = scandir($sourceDirectory);

    if ($entries === false) {
        throw new RuntimeException(sprintf('Unable to read directory [%s].', $sourceDirectory));
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $sourcePath = $sourceDirectory.'/'.$entry;
        $destinationPath = $destinationDirectory.'/'.$entry;

        if (is_dir($sourcePath)) {
            if (! mkdir($destinationPath, 0777, true) && ! is_dir($destinationPath)) {
                throw new RuntimeException(sprintf('Unable to create directory [%s].', $destinationPath));
            }

            copyDirectory($sourcePath, $destinationPath);

            continue;
        }

        if (! copy($sourcePath, $destinationPath)) {
            throw new RuntimeException(sprintf('Unable to copy fixture file [%s].', $sourcePath));
        }
    }
}
