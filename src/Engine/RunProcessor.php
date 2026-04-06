<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Engine;

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Data\SupervisorDecisionData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Support\Timeline;
use RuntimeException;
use Throwable;

final class RunProcessor
{
    public function __construct(
        private readonly WorkflowStateStore $stateStore,
        private readonly WorkflowStepExecutor $executor,
        private readonly Supervisor $supervisor,
        private readonly TemplateRenderer $templateRenderer,
    ) {}

    public function continueRun(string $runId): SupervisorDecisionData
    {
        $run = $this->stateStore->get($runId);

        if ($run === null) {
            throw new RuntimeException(sprintf('Workflow run [%s] was not found.', $runId));
        }

        if ($this->isTerminal($run)) {
            return new SupervisorDecisionData(
                action: 'noop',
                reason: 'Run is terminal.',
            );
        }

        if ($run->current_step_id === null) {
            return new SupervisorDecisionData(
                action: 'noop',
                reason: 'Run has no current step.',
            );
        }

        $decision = $this->supervisor->evaluate($run->id, $run->current_step_id);

        if ($decision->action !== 'noop') {
            return $decision;
        }

        $run = $this->stateStore->get($runId);

        if ($run === null || $this->isTerminal($run) || $run->current_step_id === null) {
            return new SupervisorDecisionData(
                action: 'noop',
                reason: 'Run is not executable.',
            );
        }

        $stepDefinition = $this->stepDefinition($run->snapshot, $run->current_step_id);
        $step = $this->latestStep($run, $run->current_step_id);

        if ($stepDefinition === null || $step === null) {
            throw new RuntimeException(sprintf(
                'Current step [%s] could not be resolved for run [%s].',
                $run->current_step_id,
                $run->id,
            ));
        }

        $input = $this->buildStepInput($run, $step, $stepDefinition);
        $running = $this->persistRunning($run, $stepDefinition->id, $input);

        try {
            $output = $this->executor->execute($stepDefinition->agent, $input);
            $completed = $this->persistCompleted($running, $stepDefinition->id, $input, $output);
        } catch (Throwable $exception) {
            $failed = $this->persistFailed($running, $stepDefinition->id, $input, $exception->getMessage());

            return $this->supervisor->evaluate($failed->id, $stepDefinition->id);
        }

        return $this->supervisor->evaluate($completed->id, $stepDefinition->id);
    }

    private function buildStepInput(
        WorkflowRunStateData $run,
        StepExecutionStateData $step,
        StepDefinitionData $stepDefinition,
    ): StepInputData {
        $renderedPrompt = $step->prompt_override
            ?? $this->templateRenderer->renderContents(
                $stepDefinition->prompt_template_contents ?? '',
                $this->promptContext($run, $stepDefinition),
                $stepDefinition->prompt_template_path,
            );

        return new StepInputData(
            step_id: $stepDefinition->id,
            run_id: $run->id,
            rendered_prompt: $renderedPrompt,
            payload: $this->stepPayload($run),
            previous_output: $this->previousOutput($run, $stepDefinition->id),
            meta: array_filter([
                ...$stepDefinition->meta,
                'output_schema' => $stepDefinition->output_schema,
                'output_schema_path' => $stepDefinition->output_schema_path,
                'tools' => $stepDefinition->tools,
                'provider_tools' => $stepDefinition->provider_tools,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function promptContext(WorkflowRunStateData $run, StepDefinitionData $stepDefinition): array
    {
        $step = $stepDefinition->toArray();
        $sources = [
            'input' => $run->input,
            'context' => $run->context,
            'output' => $run->output,
            'workflow' => $run->workflow,
            'step' => $step,
        ];

        $context = [
            ...$run->input,
            'input' => $run->input,
            'context' => $run->context,
            'output' => $run->output,
            'workflow' => $run->workflow,
            'step' => $step,
        ];

        foreach ($stepDefinition->context_map as $alias => $path) {
            if (! is_string($alias) || ! is_string($path) || $alias === '') {
                continue;
            }

            $context[$alias] = data_get($sources, $path);
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function stepPayload(WorkflowRunStateData $run): array
    {
        return [
            ...$run->input,
            'input' => $run->input,
            'context' => $run->context,
            'output' => $run->output,
        ];
    }

    private function previousOutput(WorkflowRunStateData $run, string $currentStepId): ?StepOutputData
    {
        for ($index = count($run->steps) - 1; $index >= 0; $index--) {
            $step = $run->steps[$index];

            if ($step->step_definition_id === $currentStepId || $step->output === null) {
                continue;
            }

            return $step->output;
        }

        return null;
    }

    private function persistRunning(WorkflowRunStateData $run, string $stepId, StepInputData $input): WorkflowRunStateData
    {
        return $this->stateStore->save(
            WorkflowRunStateData::from([
                ...$run->toArray(),
                'revision' => $run->revision + 1,
                'status' => 'running',
                'timeline' => array_map(
                    static fn ($entry) => $entry->toArray(),
                    Timeline::append(
                        $run->timeline,
                        'step_started',
                        'Step execution started.',
                        ['step_id' => $stepId, 'attempt' => $this->latestStep($run, $stepId)?->attempt],
                    ),
                ),
                'steps' => $this->replaceLatestStep(
                    $run,
                    $stepId,
                    StepExecutionStateData::from([
                        ...$this->latestStep($run, $stepId)?->toArray(),
                        'status' => 'running',
                        'input' => $input->toArray(),
                        'error' => null,
                    ])->toArray(),
                ),
            ]),
            $run->revision,
        );
    }

    private function persistCompleted(
        WorkflowRunStateData $run,
        string $stepId,
        StepInputData $input,
        StepOutputData $output,
    ): WorkflowRunStateData {
        return $this->stateStore->save(
            WorkflowRunStateData::from([
                ...$run->toArray(),
                'revision' => $run->revision + 1,
                'status' => 'running',
                'timeline' => array_map(
                    static fn ($entry) => $entry->toArray(),
                    Timeline::append(
                        $run->timeline,
                        'step_completed',
                        'Step execution completed.',
                        ['step_id' => $stepId],
                    ),
                ),
                'steps' => $this->replaceLatestStep(
                    $run,
                    $stepId,
                    StepExecutionStateData::from([
                        ...$this->latestStep($run, $stepId)?->toArray(),
                        'status' => 'completed',
                        'input' => $input->toArray(),
                        'output' => $output->toArray(),
                        'error' => null,
                        'completed_at' => now('UTC')->toIso8601String(),
                    ])->toArray(),
                ),
            ]),
            $run->revision,
        );
    }

    private function persistFailed(
        WorkflowRunStateData $run,
        string $stepId,
        StepInputData $input,
        string $error,
    ): WorkflowRunStateData {
        return $this->stateStore->save(
            WorkflowRunStateData::from([
                ...$run->toArray(),
                'revision' => $run->revision + 1,
                'status' => 'running',
                'timeline' => array_map(
                    static fn ($entry) => $entry->toArray(),
                    Timeline::append(
                        $run->timeline,
                        'step_failed',
                        'Step execution failed.',
                        ['step_id' => $stepId, 'error' => $error],
                    ),
                ),
                'steps' => $this->replaceLatestStep(
                    $run,
                    $stepId,
                    StepExecutionStateData::from([
                        ...$this->latestStep($run, $stepId)?->toArray(),
                        'status' => 'failed',
                        'input' => $input->toArray(),
                        'error' => $error,
                        'completed_at' => now('UTC')->toIso8601String(),
                    ])->toArray(),
                ),
            ]),
            $run->revision,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function replaceLatestStep(
        WorkflowRunStateData $run,
        string $stepId,
        array $replacement,
    ): array {
        $steps = array_map(
            static fn (StepExecutionStateData $step): array => $step->toArray(),
            $run->steps,
        );

        for ($index = count($steps) - 1; $index >= 0; $index--) {
            if (($steps[$index]['step_definition_id'] ?? null) === $stepId) {
                $steps[$index] = $replacement;

                return $steps;
            }
        }

        return $steps;
    }

    private function latestStep(WorkflowRunStateData $run, string $stepId): ?StepExecutionStateData
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

    private function stepDefinition(CompiledWorkflowData $workflow, string $stepId): ?StepDefinitionData
    {
        foreach ($workflow->steps as $step) {
            if ($step->id === $stepId) {
                return $step;
            }
        }

        return null;
    }

    private function isTerminal(WorkflowRunStateData $run): bool
    {
        return in_array($run->status, ['completed', 'failed', 'cancelled'], true);
    }
}
