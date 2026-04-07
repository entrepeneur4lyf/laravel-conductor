<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Engine;

use Carbon\CarbonImmutable;
use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Data\SupervisorDecisionData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunResultData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotFoundException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunRevisionMismatchException;
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
        private readonly RunLockProvider $lockProvider,
    ) {}

    public function continueRun(string $runId): WorkflowRunResultData
    {
        return $this->lockProvider->withLock($runId, function () use ($runId): WorkflowRunResultData {
            $run = $this->stateStore->get($runId);

            if ($run === null) {
                throw new RunNotFoundException($runId);
            }

            // Retry backoff short-circuit: if a prior failure-handler-driven
            // retry scheduled a delay, honor it by returning noop until the
            // backoff elapses. Layer 2 of the concurrency defense still runs
            // on later invocations after the backoff clears.
            if ($run->retry_after !== null) {
                $retryAfter = CarbonImmutable::parse($run->retry_after);
                if ($retryAfter->isFuture()) {
                    return $this->buildResult(
                        $run,
                        new SupervisorDecisionData(
                            action: 'noop',
                            reason: sprintf(
                                'Run is in retry backoff until %s.',
                                $retryAfter->toIso8601String(),
                            ),
                        ),
                    );
                }
            }

            if ($this->isTerminal($run)) {
                return $this->buildResult(
                    $run,
                    new SupervisorDecisionData(
                        action: 'noop',
                        reason: 'Run is terminal.',
                    ),
                );
            }

            if ($run->current_step_id === null) {
                return $this->buildResult(
                    $run,
                    new SupervisorDecisionData(
                        action: 'noop',
                        reason: 'Run has no current step.',
                    ),
                );
            }

            $decision = $this->supervisor->evaluate($run->id, $run->current_step_id);

            if ($decision->action !== 'noop') {
                return $this->buildResult($this->stateStore->get($runId) ?? $run, $decision);
            }

            $refreshed = $this->stateStore->get($runId);

            if ($refreshed === null || $this->isTerminal($refreshed) || $refreshed->current_step_id === null) {
                return $this->buildResult(
                    $refreshed ?? $run,
                    new SupervisorDecisionData(
                        action: 'noop',
                        reason: 'Run is not executable.',
                    ),
                );
            }

            $run = $refreshed;

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

            // Layer 2 of the run-level concurrency defense: re-read the run
            // immediately before the (expensive) executor call and confirm
            // the locally held revision still matches what is persisted. If
            // a concurrent request slipped through after the cache lock TTL
            // expired and advanced the run, bail with a typed exception
            // before any LLM tokens are burned. The final write layer
            // (OptimisticRunMutator) is the authoritative correctness gate;
            // this check just makes the wasted-work window as small as we
            // can without async I/O.
            $currentState = $this->stateStore->get($running->id);
            if ($currentState === null) {
                throw new RunNotFoundException($running->id);
            }
            if ($currentState->revision !== $running->revision) {
                throw new RunRevisionMismatchException(
                    $running->id,
                    $running->revision,
                    $currentState->revision,
                );
            }

            try {
                $output = $this->executor->execute($stepDefinition->agent, $input);
                $completed = $this->persistCompleted($running, $stepDefinition->id, $input, $output);
            } catch (Throwable $exception) {
                $failed = $this->persistFailed($running, $stepDefinition->id, $input, $exception->getMessage());
                $failureDecision = $this->supervisor->evaluate($failed->id, $stepDefinition->id);

                return $this->buildResult($this->stateStore->get($runId) ?? $failed, $failureDecision);
            }

            $finalDecision = $this->supervisor->evaluate($completed->id, $stepDefinition->id);

            return $this->buildResult($this->stateStore->get($runId) ?? $completed, $finalDecision);
        });
    }

    private function buildResult(?WorkflowRunStateData $run, SupervisorDecisionData $decision): WorkflowRunResultData
    {
        if ($run === null) {
            throw new RuntimeException('Workflow run state could not be resolved while building the result.');
        }

        return new WorkflowRunResultData(
            run: $run,
            decision: $decision,
        );
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
                'retry_after' => null,
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
                'retry_after' => null,
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
                'retry_after' => null,
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
