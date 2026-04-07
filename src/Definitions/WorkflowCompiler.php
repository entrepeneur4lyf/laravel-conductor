<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Definitions;

use Carbon\CarbonImmutable;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\FailureHandlerData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Engine\SchemaValidator;
use Entrepeneur4lyf\LaravelConductor\Engine\TemplateRenderer;

final class WorkflowCompiler
{
    public function __construct(
        private readonly WorkflowDefinitionValidator $validator,
        private readonly TemplateRenderer $templateRenderer,
        private readonly SchemaValidator $schemaValidator,
    ) {}

    public function compile(
        WorkflowDefinitionData|LoadedWorkflowDefinition $definition,
        ?string $sourcePath = null,
    ): CompiledWorkflowData {
        [$definition, $sourcePath] = $this->normalizeCompileInput($definition, $sourcePath);

        $this->validator->validate($definition, $sourcePath);

        $compiledSteps = array_map(
            fn (StepDefinitionData $step): StepDefinitionData => $this->compileStep($step, $sourcePath),
            $definition->steps,
        );

        $compiledFailureHandlers = array_map(
            fn (FailureHandlerData $failureHandler): FailureHandlerData => $this->compileFailureHandler(
                $failureHandler,
                $sourcePath
            ),
            $definition->failure_handlers,
        );

        return new CompiledWorkflowData(
            name: $definition->name,
            version: $definition->version,
            compiled_at: CarbonImmutable::now('UTC')->toIso8601String(),
            source_hash: $this->sourceHash($sourcePath, $compiledSteps, $compiledFailureHandlers),
            steps: $compiledSteps,
            failure_handlers: $compiledFailureHandlers,
            defaults: $definition->defaults,
            description: $definition->description,
        );
    }

    /**
     * @return array{0: WorkflowDefinitionData, 1: string}
     */
    private function normalizeCompileInput(
        WorkflowDefinitionData|LoadedWorkflowDefinition $definition,
        ?string $sourcePath,
    ): array {
        if ($definition instanceof LoadedWorkflowDefinition) {
            return [$definition->definition, $definition->sourcePath];
        }

        if ($sourcePath === null || trim($sourcePath) === '') {
            throw new \InvalidArgumentException(
                'Workflow compilation requires a source path when compiling a raw workflow definition.'
            );
        }

        return [$definition, $sourcePath];
    }

    private function compileStep(StepDefinitionData $step, string $sourcePath): StepDefinitionData
    {
        $promptTemplatePath = $step->prompt_template === null
            ? null
            : $this->templateRenderer->resolvePath($step->prompt_template, $sourcePath);
        $outputSchemaPath = $step->output_schema === null
            ? null
            : $this->schemaValidator->resolvePath($step->output_schema, $sourcePath);

        return new StepDefinitionData(
            id: $step->id,
            agent: $step->agent,
            prompt_template: $step->prompt_template,
            output_schema: $step->output_schema,
            prompt_template_path: $promptTemplatePath,
            prompt_template_contents: $this->readFileContents($promptTemplatePath),
            output_schema_path: $outputSchemaPath,
            output_schema_contents: $this->readFileContents($outputSchemaPath),
            wait_for: $step->wait_for,
            context_map: $step->context_map,
            parallel: $step->parallel,
            foreach: $step->foreach,
            retries: $step->retries,
            timeout: $step->timeout,
            on_success: $step->on_success,
            on_fail: $step->on_fail,
            condition: $step->condition,
            quality_rules: $step->quality_rules,
            tools: $step->tools,
            provider_tools: $step->provider_tools,
            meta: $step->meta,
        );
    }

    private function compileFailureHandler(FailureHandlerData $failureHandler, string $sourcePath): FailureHandlerData
    {
        $promptTemplatePath = $failureHandler->prompt_template === null
            ? null
            : $this->templateRenderer->resolvePath($failureHandler->prompt_template, $sourcePath);

        return new FailureHandlerData(
            match: $failureHandler->match,
            action: $failureHandler->action,
            delay: $failureHandler->delay,
            prompt_template: $failureHandler->prompt_template,
            prompt_template_path: $promptTemplatePath,
            prompt_template_contents: $this->readFileContents($promptTemplatePath),
        );
    }

    /**
     * @param  array<int, StepDefinitionData>  $steps
     * @param  array<int, FailureHandlerData>  $failureHandlers
     */
    private function sourceHash(string $sourcePath, array $steps, array $failureHandlers): string
    {
        $contents = $this->readFileContents($sourcePath);

        /** @var \HashContext $hash */
        $hash = hash_init('sha256');

        hash_update($hash, $contents ?? '');
        hash_update($hash, "\0");

        foreach ($steps as $step) {
            $this->updateHashWithAsset($hash, $step->prompt_template, $step->prompt_template_contents);
            $this->updateHashWithAsset($hash, $step->output_schema, $step->output_schema_contents);
        }

        foreach ($failureHandlers as $failureHandler) {
            $this->updateHashWithAsset($hash, $failureHandler->prompt_template, $failureHandler->prompt_template_contents);
        }

        return 'sha256:'.hash_final($hash);
    }

    private function readFileContents(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read file [%s].', $path));
        }

        return $contents;
    }

    private function updateHashWithAsset(\HashContext $hash, ?string $reference, ?string $contents): void
    {
        if ($reference === null) {
            return;
        }

        hash_update($hash, $reference);
        hash_update($hash, "\0");
        hash_update($hash, $contents ?? '');
        hash_update($hash, "\0");
    }
}
