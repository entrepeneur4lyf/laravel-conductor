<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Engine;

use Carbon\CarbonImmutable;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\CompiledWorkflowData;
use Entrepeneur4lyf\LaravelConductor\Data\FailureHandlerData;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\SupervisorDecisionData;
use Entrepeneur4lyf\LaravelConductor\Data\WaitStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Events\RunWaiting;
use Entrepeneur4lyf\LaravelConductor\Events\StepRetrying;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowCancelled;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowCompleted;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowFailed;
use Entrepeneur4lyf\LaravelConductor\Support\Timeline;
use Illuminate\Support\Str;
use RuntimeException;

final class Supervisor
{
    public function __construct(
        private readonly WorkflowStateStore $stateStore,
        private readonly TemplateRenderer $templateRenderer,
        private readonly SchemaValidator $schemaValidator,
        private readonly FailureHandlerMatcher $failureHandlerMatcher,
        private readonly QualityRuleEvaluator $qualityRuleEvaluator,
        private readonly IdempotencyGuard $idempotencyGuard,
        private readonly EscalationEvaluator $escalationEvaluator,
    ) {}

    public function evaluate(string $runId, string $stepId): SupervisorDecisionData
    {
        $run = $this->stateStore->get($runId);

        if ($run === null) {
            throw new RuntimeException(sprintf('Workflow run [%s] was not found.', $runId));
        }

        $step = $this->latestStep($run, $stepId);
        $guard = $this->idempotencyGuard->forEvaluation($run, $step, $stepId);

        if ($guard !== null) {
            return $guard;
        }

        $stepDefinition = $this->stepDefinition($run->snapshot, $stepId);

        if ($stepDefinition === null) {
            return $this->fail($run, $stepId, 'Step definition could not be found.');
        }

        if ($step->status === 'pending') {
            if ($this->shouldSkip($run, $stepDefinition)) {
                return $this->skip($run, $step, $stepDefinition, 'Step condition evaluated false.');
            }

            if ($stepDefinition->wait_for !== null) {
                return $this->wait($run, $step, $stepDefinition);
            }

            return new SupervisorDecisionData(
                action: 'noop',
                reason: 'Pending step has no deterministic supervisor action yet.',
            );
        }

        if ($step->status === 'skipped') {
            return $this->advance($run, $step, $stepDefinition);
        }

        if ($step->status === 'failed') {
            return $this->handleFailure(
                $run,
                $step,
                $stepDefinition,
                $run->snapshot->failure_handlers,
                $step->error ?? 'unknown_error',
            );
        }

        if ($step->status !== 'completed') {
            return new SupervisorDecisionData(
                action: 'noop',
                reason: sprintf('Unsupported step status [%s] for evaluation.', $step->status),
            );
        }

        $payload = $step->output === null ? [] : $step->output->payload;

        try {
            if ($stepDefinition->output_schema_contents !== null) {
                $this->schemaValidator->validateContents(
                    $payload,
                    $stepDefinition->output_schema_contents,
                    $stepDefinition->output_schema_path ?? $stepDefinition->output_schema ?? 'compiled-schema',
                );
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->handleFailure(
                $run,
                $step,
                $stepDefinition,
                $run->snapshot->failure_handlers,
                'schema_validation_failed: '.$exception->getMessage(),
            );
        }

        $quality = $this->qualityRuleEvaluator->evaluate($payload, $stepDefinition->quality_rules);

        if (! $quality->passed) {
            return $this->handleFailure(
                $run,
                $step,
                $stepDefinition,
                $run->snapshot->failure_handlers,
                'quality_rule_failed: '.$quality->failures[0],
            );
        }

        return $this->advance($run, $step, $stepDefinition);
    }

    private function advance(
        WorkflowRunStateData $run,
        StepExecutionStateData $step,
        StepDefinitionData $stepDefinition,
        string $decisionAction = 'advance',
        ?string $decisionReason = null,
        ?array $timeline = null,
    ): SupervisorDecisionData {
        $target = $stepDefinition->on_success;
        $updatedSteps = $this->replaceLatestStep(
            $run,
            $stepDefinition->id,
            $this->withDecision(
                $step,
                status: $step->status === 'pending' ? 'skipped' : $step->status,
                action: $decisionAction,
                reason: $decisionReason ?? ($target === 'complete'
                    ? 'Workflow completed.'
                    : sprintf('Advancing to %s.', $target)),
            ),
        );

        if ($target === 'complete') {
            $stored = $this->persist(
                $run,
                status: 'completed',
                revision: $run->revision + 1,
                currentStepId: null,
                output: $step->output?->payload,
                steps: $updatedSteps,
                wait: null,
                timeline: Timeline::append(
                    $timeline ?? $run->timeline,
                    'workflow_completed',
                    'Workflow completed.',
                    ['step_id' => $stepDefinition->id],
                ),
            );

            event(new WorkflowCompleted($stored));

            return new SupervisorDecisionData(
                action: 'complete',
                reason: 'Workflow completed.',
            );
        }

        if ($target === 'discard') {
            $stored = $this->persist(
                $run,
                status: 'cancelled',
                revision: $run->revision + 1,
                currentStepId: null,
                output: $run->output,
                steps: $updatedSteps,
                wait: null,
                timeline: Timeline::append(
                    $timeline ?? $run->timeline,
                    'workflow_cancelled',
                    'Workflow discarded.',
                    ['step_id' => $stepDefinition->id],
                ),
            );

            event(new WorkflowCancelled($stored, 'Workflow discarded.'));

            return new SupervisorDecisionData(
                action: 'cancel',
                reason: 'Workflow discarded.',
            );
        }

        $nextDefinition = $this->stepDefinition($run->snapshot, $target);

        if ($nextDefinition === null) {
            return $this->fail($run, $stepDefinition->id, sprintf('Next step [%s] could not be found.', $target));
        }

        $updatedSteps[] = new StepExecutionStateData(
            step_definition_id: $nextDefinition->id,
            status: 'pending',
            attempt: 1,
        );

        $this->persist(
            $run,
            status: 'running',
            revision: $run->revision + 1,
            currentStepId: $nextDefinition->id,
            output: $run->output,
            steps: $updatedSteps,
            wait: null,
            timeline: $timeline ?? $run->timeline,
        );

        return new SupervisorDecisionData(
            action: $decisionAction,
            next_step_id: $nextDefinition->id,
            reason: $decisionReason ?? sprintf('Continuing to %s.', $nextDefinition->id),
        );
    }

    /**
     * @param  array<int, FailureHandlerData>  $handlers
     */
    private function handleFailure(
        WorkflowRunStateData $run,
        StepExecutionStateData $step,
        StepDefinitionData $stepDefinition,
        array $handlers,
        string $error,
    ): SupervisorDecisionData {
        $handler = $this->failureHandlerMatcher->match($handlers, $error);
        $canRetry = $step->attempt <= $stepDefinition->retries;

        if ($handler === null) {
            return $canRetry
                ? $this->escalate($run, $step, $stepDefinition, $error)
                : $this->fail($run, $stepDefinition->id, $error);
        }

        return match ($handler->action) {
            'retry' => $canRetry
                ? $this->retry($run, $step, $stepDefinition, $error, $handler)
                : $this->fail($run, $stepDefinition->id, $error),
            'retry_with_prompt' => $canRetry
                ? $this->retry($run, $step, $stepDefinition, $error, $handler, true)
                : $this->fail($run, $stepDefinition->id, $error),
            'escalate' => $this->escalate($run, $step, $stepDefinition, $error),
            'skip' => $this->skip($run, $step, $stepDefinition, $error),
            'wait' => $this->wait($run, $step, $stepDefinition, $handler->delay),
            'fail' => $this->fail($run, $stepDefinition->id, $error),
            default => $this->fail($run, $stepDefinition->id, $error),
        };
    }

    private function retry(
        WorkflowRunStateData $run,
        StepExecutionStateData $step,
        StepDefinitionData $stepDefinition,
        string $error,
        FailureHandlerData $handler,
        bool $withPromptOverride = false,
    ): SupervisorDecisionData {
        $current = $this->withDecision(
            $step,
            status: 'retrying',
            action: $withPromptOverride ? 'retry_with_prompt' : 'retry',
            reason: $error,
        );

        $promptOverride = $withPromptOverride
            ? $this->renderFailurePrompt($handler, $stepDefinition, $error)
            : null;

        $retry = new StepExecutionStateData(
            step_definition_id: $stepDefinition->id,
            status: 'pending',
            attempt: $step->attempt + 1,
            prompt_override: $promptOverride,
        );

        $steps = $this->replaceLatestStep($run, $stepDefinition->id, $current);
        $steps[] = $retry;

        $retryAfter = ($handler->delay ?? 0) > 0
            ? CarbonImmutable::now('UTC')->addSeconds((int) $handler->delay)->toIso8601String()
            : null;

        $stored = $this->persist(
            $run,
            status: 'running',
            revision: $run->revision + 1,
            currentStepId: $stepDefinition->id,
            output: $run->output,
            steps: $steps,
            wait: null,
            timeline: Timeline::append(
                $run->timeline,
                'step_retried',
                'Step retry scheduled.',
                ['step_id' => $stepDefinition->id, 'attempt' => $retry->attempt, 'reason' => $error],
            ),
            retryAfter: $retryAfter,
        );

        event(new StepRetrying($stored, $stepDefinition->id, $error));

        return new SupervisorDecisionData(
            action: $withPromptOverride ? 'retry_with_prompt' : 'retry',
            reason: $error,
            modified_prompt: $promptOverride,
            delay: $handler->delay,
        );
    }

    private function skip(
        WorkflowRunStateData $run,
        StepExecutionStateData $step,
        StepDefinitionData $stepDefinition,
        string $reason,
    ): SupervisorDecisionData {
        return $this->advance(
            $run,
            $step,
            $stepDefinition,
            'skip',
            $reason,
            Timeline::append(
                $run->timeline,
                'step_skipped',
                'Step skipped by supervisor.',
                ['step_id' => $stepDefinition->id, 'reason' => $reason],
            ),
        );
    }

    private function wait(
        WorkflowRunStateData $run,
        StepExecutionStateData $step,
        StepDefinitionData $stepDefinition,
        ?int $delay = null,
    ): SupervisorDecisionData {
        $wait = new WaitStateData(
            wait_type: $stepDefinition->wait_for ?? 'handler_wait',
            resume_token: (string) Str::uuid(),
            timeout_at: $delay === null ? null : now('UTC')->addSeconds($delay)->toIso8601String(),
        );

        $steps = $this->replaceLatestStep(
            $run,
            $stepDefinition->id,
            $this->withDecision(
                $step,
                status: $step->status,
                action: 'wait',
                reason: sprintf('Waiting for %s.', $wait->wait_type),
            ),
        );

        $stored = $this->persist(
            $run,
            status: 'waiting',
            revision: $run->revision + 1,
            currentStepId: $stepDefinition->id,
            output: $run->output,
            steps: $steps,
            wait: $wait,
            timeline: Timeline::append(
                $run->timeline,
                'step_waiting',
                sprintf('Step is waiting for %s.', $wait->wait_type),
                ['step_id' => $stepDefinition->id, 'wait_type' => $wait->wait_type],
            ),
        );

        event(new RunWaiting($stored, $stepDefinition->id, $wait->wait_type));

        return new SupervisorDecisionData(
            action: 'wait',
            reason: sprintf('Waiting for %s.', $wait->wait_type),
        );
    }

    private function fail(WorkflowRunStateData $run, string $stepId, string $reason): SupervisorDecisionData
    {
        $step = $this->latestStep($run, $stepId);

        if ($step === null) {
            throw new RuntimeException(sprintf('Step [%s] could not be found on run [%s].', $stepId, $run->id));
        }

        $steps = $this->replaceLatestStep(
            $run,
            $stepId,
            $this->withDecision(
                $step,
                status: 'failed',
                action: 'fail',
                reason: $reason,
            ),
        );

        $stored = $this->persist(
            $run,
            status: 'failed',
            revision: $run->revision + 1,
            currentStepId: $run->current_step_id,
            output: $run->output,
            steps: $steps,
            wait: null,
            timeline: Timeline::append(
                Timeline::append(
                    $run->timeline,
                    'step_failed',
                    'Step failed.',
                    ['step_id' => $stepId, 'reason' => $reason],
                ),
                'workflow_failed',
                'Workflow failed.',
                ['step_id' => $stepId, 'reason' => $reason],
            ),
        );

        event(new WorkflowFailed($stored, $stepId, $reason));

        return new SupervisorDecisionData(
            action: 'fail',
            reason: $reason,
        );
    }

    private function shouldSkip(WorkflowRunStateData $run, StepDefinitionData $stepDefinition): bool
    {
        if ($stepDefinition->condition === null) {
            return false;
        }

        return ! $this->evaluateCondition($run, $stepDefinition->condition);
    }

    private function evaluateCondition(WorkflowRunStateData $run, string $condition): bool
    {
        if (strtolower(trim($condition)) === 'false') {
            return false;
        }

        if (strtolower(trim($condition)) === 'true') {
            return true;
        }

        if (! preg_match('/^(input|context|output)\.(.+?)\s*(==|!=)\s*(.+)$/', $condition, $matches)) {
            return true;
        }

        $scope = $matches[1];
        $path = $matches[2];
        $operator = $matches[3];
        $expected = $this->normalizeScalar(trim($matches[4]));
        $source = match ($scope) {
            'input' => $run->input,
            'context' => $run->context,
            'output' => $run->output ?? [],
        };
        $actual = data_get($source, $path);

        if ($operator === '==') {
            return $actual == $expected;
        }

        return $actual != $expected;
    }

    private function normalizeScalar(string $value): mixed
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => is_numeric($value) ? (float) $value : trim($value, '\'"'),
        };
    }

    private function renderFailurePrompt(FailureHandlerData $handler, StepDefinitionData $stepDefinition, string $error): ?string
    {
        if ($handler->prompt_template_contents === null) {
            return null;
        }

        return $this->templateRenderer->renderContents(
            $handler->prompt_template_contents,
            [
                'step_id' => $stepDefinition->id,
                'error' => $error,
            ],
            $handler->prompt_template_path ?? $handler->prompt_template,
        );
    }

    private function escalate(
        WorkflowRunStateData $run,
        StepExecutionStateData $step,
        StepDefinitionData $stepDefinition,
        string $error,
    ): SupervisorDecisionData {
        $decision = $this->escalationEvaluator->evaluate(
            stepId: $stepDefinition->id,
            error: $error,
            stepOutput: $step->output === null ? [] : $step->output->payload,
            originalPrompt: $step->prompt_override
                ?? ($step->input === null ? null : $step->input->rendered_prompt)
                ?? ($stepDefinition->prompt_template_contents ?? ''),
            attempt: $step->attempt,
            maxRetries: $stepDefinition->retries,
        );

        return match ($decision->action) {
            'retry' => $step->attempt <= $stepDefinition->retries
                ? $this->retryFromEscalation($run, $step, $stepDefinition, $decision)
                : $this->fail($run, $stepDefinition->id, $decision->reason ?? $error),
            'skip' => $this->skip($run, $step, $stepDefinition, $decision->reason ?? $error),
            default => $this->fail($run, $stepDefinition->id, $decision->reason ?? $error),
        };
    }

    private function retryFromEscalation(
        WorkflowRunStateData $run,
        StepExecutionStateData $step,
        StepDefinitionData $stepDefinition,
        SupervisorDecisionData $decision,
    ): SupervisorDecisionData {
        $current = $this->withDecision(
            $step,
            status: 'retrying',
            action: 'retry',
            reason: $decision->reason ?? 'AI escalation requested retry.',
        );

        $retry = new StepExecutionStateData(
            step_definition_id: $stepDefinition->id,
            status: 'pending',
            attempt: $step->attempt + 1,
            prompt_override: $decision->modified_prompt,
        );

        $steps = $this->replaceLatestStep($run, $stepDefinition->id, $current);
        $steps[] = $retry;

        $stored = $this->persist(
            $run,
            status: 'running',
            revision: $run->revision + 1,
            currentStepId: $stepDefinition->id,
            output: $run->output,
            steps: $steps,
            wait: null,
            timeline: Timeline::append(
                $run->timeline,
                'step_retried',
                'AI escalation scheduled a retry.',
                ['step_id' => $stepDefinition->id, 'attempt' => $retry->attempt],
            ),
        );

        event(new StepRetrying($stored, $stepDefinition->id, $decision->reason ?? 'AI escalation requested retry.'));

        return new SupervisorDecisionData(
            action: 'retry',
            reason: $decision->reason,
            modified_prompt: $decision->modified_prompt,
        );
    }

    private function latestStep(WorkflowRunStateData $run, string $stepId): ?StepExecutionStateData
    {
        $matching = array_values(array_filter(
            $run->steps,
            static fn (StepExecutionStateData $step): bool => $step->step_definition_id === $stepId,
        ));

        if ($matching === []) {
            return null;
        }

        return $matching[array_key_last($matching)];
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

    /**
     * @return array<int, StepExecutionStateData>
     */
    private function replaceLatestStep(
        WorkflowRunStateData $run,
        string $stepId,
        StepExecutionStateData $replacement,
    ): array {
        $steps = $run->steps;

        for ($index = count($steps) - 1; $index >= 0; $index--) {
            if ($steps[$index]->step_definition_id === $stepId) {
                $steps[$index] = $replacement;

                return $steps;
            }
        }

        throw new RuntimeException(sprintf('Step [%s] could not be replaced on run [%s].', $stepId, $run->id));
    }

    private function withDecision(
        StepExecutionStateData $step,
        string $status,
        string $action,
        string $reason,
    ): StepExecutionStateData {
        return new StepExecutionStateData(
            step_definition_id: $step->step_definition_id,
            status: $status,
            attempt: $step->attempt,
            batch_index: $step->batch_index,
            input: $step->input,
            output: $step->output,
            error: $step->error,
            supervisor_decision: new SupervisorDecisionData(
                action: $action,
                reason: $reason,
            ),
            prompt_override: $step->prompt_override,
            supervisor_feedback: $reason,
            completed_at: $status === 'completed' ? now('UTC')->toIso8601String() : $step->completed_at,
        );
    }

    /**
     * @param  array<int, StepExecutionStateData>  $steps
     */
    private function persist(
        WorkflowRunStateData $run,
        string $status,
        int $revision,
        ?string $currentStepId,
        ?array $output,
        array $steps,
        ?WaitStateData $wait,
        array $timeline,
        ?string $retryAfter = null,
    ): WorkflowRunStateData {
        return $this->stateStore->save(
            new WorkflowRunStateData(
                id: $run->id,
                workflow: $run->workflow,
                workflow_version: $run->workflow_version,
                revision: $revision,
                status: $status,
                snapshot: $run->snapshot,
                current_step_id: $currentStepId,
                input: $run->input,
                output: $output,
                context: $run->context,
                wait: $wait,
                retry_after: $retryAfter,
                steps: $steps,
                timeline: $timeline,
            ),
            $run->revision,
        );
    }
}
