<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Definitions;

use Entrepeneur4lyf\LaravelConductor\Data\FailureHandlerData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Engine\SchemaValidator;
use Entrepeneur4lyf\LaravelConductor\Engine\TemplateRenderer;

final class WorkflowDefinitionValidator
{
    /** @var list<string> */
    private const SUPPORTED_FAILURE_HANDLER_ACTIONS = [
        'retry',
        'retry_with_prompt',
        'skip',
        'wait',
        'escalate',
        'fail',
    ];

    /** @var list<string> */
    private const SUPPORTED_TERMINAL_TARGETS = [
        'complete',
        'discard',
        'fail',
        'cancel',
    ];

    public function __construct(
        private readonly TemplateRenderer $templateRenderer,
        private readonly SchemaValidator $schemaValidator,
    ) {}

    public function validate(WorkflowDefinitionData $definition, string $sourcePath): void
    {
        if ($definition->steps === []) {
            throw new \InvalidArgumentException('Workflow definition must declare at least one step.');
        }

        $stepIds = [];

        foreach ($definition->steps as $step) {
            if (isset($stepIds[$step->id])) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate step id [%s] in workflow definition.',
                    $step->id
                ));
            }

            $stepIds[$step->id] = true;
        }

        foreach ($definition->steps as $step) {
            $this->validateStep($step, $sourcePath, $stepIds);
        }

        foreach ($definition->failure_handlers as $failureHandler) {
            $this->validateFailureHandler($failureHandler, $sourcePath);
        }
    }

    /**
     * @param  array<string, bool>  $knownStepIds
     */
    private function validateStep(StepDefinitionData $step, string $sourcePath, array $knownStepIds): void
    {
        if ($step->retries < 0) {
            throw new \InvalidArgumentException(sprintf(
                'Step [%s] must define retries greater than or equal to 0.',
                $step->id,
            ));
        }

        if ($step->timeout <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'Step [%s] must define timeout greater than 0 seconds.',
                $step->id,
            ));
        }

        if ($step->parallel && ($step->foreach === null || trim($step->foreach) === '')) {
            throw new \InvalidArgumentException(sprintf(
                'Step [%s] cannot enable parallel execution without declaring foreach.',
                $step->id,
            ));
        }

        if ($step->prompt_template !== null) {
            $promptTemplatePath = $this->templateRenderer->resolvePath($step->prompt_template, $sourcePath);
            $this->templateRenderer->assertValidSyntax($promptTemplatePath);
        }

        if ($step->output_schema !== null) {
            $schemaPath = $this->schemaValidator->resolvePath($step->output_schema, $sourcePath);
            $this->schemaValidator->assertValidSchemaFile($schemaPath);
        }

        $this->assertValidTransitionTarget($step, 'on_success', $step->on_success, $knownStepIds);
        $this->assertValidTransitionTarget($step, 'on_fail', $step->on_fail, $knownStepIds);
    }

    private function validateFailureHandler(FailureHandlerData $failureHandler, string $sourcePath): void
    {
        if ($failureHandler->delay !== null && $failureHandler->delay < 0) {
            throw new \InvalidArgumentException(sprintf(
                'Failure handler [%s] must define delay greater than or equal to 0.',
                $failureHandler->match,
            ));
        }

        if (! in_array($failureHandler->action, self::SUPPORTED_FAILURE_HANDLER_ACTIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Failure handler [%s] has unsupported action [%s]. Supported actions: [%s].',
                $failureHandler->match,
                $failureHandler->action,
                implode(', ', self::SUPPORTED_FAILURE_HANDLER_ACTIONS),
            ));
        }

        if ($failureHandler->action === 'retry_with_prompt' && $failureHandler->prompt_template === null) {
            throw new \InvalidArgumentException(sprintf(
                'Failure handler [%s] with action [retry_with_prompt] must define prompt_template.',
                $failureHandler->match,
            ));
        }

        if ($failureHandler->prompt_template !== null) {
            $promptTemplatePath = $this->templateRenderer->resolvePath($failureHandler->prompt_template, $sourcePath);
            $this->templateRenderer->assertValidSyntax($promptTemplatePath);
        }
    }

    /**
     * @param  array<string, bool>  $knownStepIds
     */
    private function assertValidTransitionTarget(
        StepDefinitionData $step,
        string $field,
        ?string $target,
        array $knownStepIds,
    ): void {
        if ($target === null || isset($knownStepIds[$target]) || in_array($target, self::SUPPORTED_TERMINAL_TARGETS, true)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Step [%s] has invalid %s target [%s]. Supported terminal targets: [%s].',
            $step->id,
            $field,
            $target,
            implode(', ', self::SUPPORTED_TERMINAL_TARGETS),
        ));
    }
}
