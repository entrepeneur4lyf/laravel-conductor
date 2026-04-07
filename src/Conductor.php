<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor;

use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunResultData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\RunProcessor;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;
use Entrepeneur4lyf\LaravelConductor\Engine\WorkflowEngine;
use Entrepeneur4lyf\LaravelConductor\Events\StepRetrying;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowCancelled;
use Entrepeneur4lyf\LaravelConductor\Exceptions\CurrentStepNotFoundException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\InvalidResumeTokenException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotCancellableException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotFoundException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotRetryableException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotWaitingException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunRevisionMismatchException;
use Entrepeneur4lyf\LaravelConductor\Support\Timeline;

final class Conductor
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly RunProcessor $processor,
        private readonly WorkflowStateStore $stateStore,
        private readonly Supervisor $supervisor,
        private readonly RunLockProvider $lockProvider,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function start(string $workflow, array $input = []): WorkflowRunStateData
    {
        return $this->engine->start($workflow, $input);
    }

    public function continueRun(string $runId): WorkflowRunResultData
    {
        return $this->processor->continueRun($runId);
    }

    public function getRun(string $runId): ?WorkflowRunStateData
    {
        return $this->stateStore->get($runId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resumeRun(string $runId, string $resumeToken, array $payload = []): WorkflowRunResultData
    {
        return $this->lockProvider->withLock($runId, function () use ($runId, $resumeToken, $payload): WorkflowRunResultData {
            $run = $this->requireRun($runId);

            if ($run->status !== 'waiting' || $run->wait === null || $run->current_step_id === null) {
                throw new RunNotWaitingException($runId);
            }

            if ($resumeToken !== $run->wait->resume_token) {
                throw new InvalidResumeTokenException($runId);
            }

            $step = $this->latestStep($run, $run->current_step_id);

            if ($step === null) {
                throw new CurrentStepNotFoundException($runId, $run->current_step_id);
            }

            $updatedRun = $this->stateStore->save(
                WorkflowRunStateData::from([
                    ...$run->toArray(),
                    'revision' => $run->revision + 1,
                    'status' => 'running',
                    'wait' => null,
                    'retry_after' => null,
                    'timeline' => array_map(
                        static fn ($entry) => $entry->toArray(),
                        Timeline::append(
                            $run->timeline,
                            'workflow_resumed',
                            'Workflow resumed from a waiting state.',
                            ['step_id' => $run->current_step_id],
                        ),
                    ),
                    'steps' => $this->replaceLatestStep(
                        $run,
                        $run->current_step_id,
                        StepExecutionStateData::from([
                            ...$step->toArray(),
                            'status' => 'completed',
                            'output' => StepOutputData::from([
                                'step_id' => $run->current_step_id,
                                'run_id' => $run->id,
                                'status' => 'completed',
                                'payload' => $payload,
                            ])->toArray(),
                            'supervisor_decision' => null,
                            'supervisor_feedback' => null,
                            'completed_at' => now('UTC')->toIso8601String(),
                        ])->toArray(),
                    ),
                ]),
                $run->revision,
            );

            $decision = $this->supervisor->evaluate(
                $updatedRun->id,
                $updatedRun->current_step_id ?? $run->current_step_id,
            );

            $fresh = $this->stateStore->get($runId) ?? $updatedRun;

            return new WorkflowRunResultData(
                run: $fresh,
                decision: $decision,
            );
        });
    }

    public function retryRun(string $runId, int $revision): WorkflowRunStateData
    {
        return $this->lockProvider->withLock($runId, function () use ($runId, $revision): WorkflowRunStateData {
            $run = $this->requireRun($runId);

            if ($revision !== $run->revision) {
                throw new RunRevisionMismatchException($runId, $revision, $run->revision);
            }

            if ($run->status !== 'failed' || $run->current_step_id === null) {
                throw new RunNotRetryableException($runId);
            }

            $step = $this->latestStep($run, $run->current_step_id);

            if ($step === null) {
                throw new CurrentStepNotFoundException($runId, $run->current_step_id);
            }

            $steps = array_map(
                static fn (StepExecutionStateData $existing): array => $existing->toArray(),
                $run->steps,
            );
            $steps[] = StepExecutionStateData::from([
                'step_definition_id' => $run->current_step_id,
                'status' => 'pending',
                'attempt' => $step->attempt + 1,
            ])->toArray();

            $updatedRun = $this->stateStore->save(
                WorkflowRunStateData::from([
                    ...$run->toArray(),
                    'revision' => $run->revision + 1,
                    'status' => 'running',
                    'retry_after' => null,
                    'timeline' => array_map(
                        static fn ($entry) => $entry->toArray(),
                        Timeline::append(
                            $run->timeline,
                            'step_retried',
                            'Manual retry requested.',
                            ['step_id' => $run->current_step_id, 'attempt' => $step->attempt + 1],
                        ),
                    ),
                    'steps' => $steps,
                ]),
                $run->revision,
            );

            event(new StepRetrying($updatedRun, $run->current_step_id, 'Manual retry requested.'));

            return $updatedRun;
        });
    }

    public function cancelRun(string $runId, int $revision): WorkflowRunStateData
    {
        return $this->lockProvider->withLock($runId, function () use ($runId, $revision): WorkflowRunStateData {
            $run = $this->requireRun($runId);

            if ($revision !== $run->revision) {
                throw new RunRevisionMismatchException($runId, $revision, $run->revision);
            }

            if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
                throw new RunNotCancellableException($runId);
            }

            $updatedRun = $this->stateStore->save(
                WorkflowRunStateData::from([
                    ...$run->toArray(),
                    'revision' => $run->revision + 1,
                    'status' => 'cancelled',
                    'current_step_id' => null,
                    'wait' => null,
                    'retry_after' => null,
                    'timeline' => array_map(
                        static fn ($entry) => $entry->toArray(),
                        Timeline::append(
                            $run->timeline,
                            'workflow_cancelled',
                            'Manual cancellation requested.',
                        ),
                    ),
                ]),
                $run->revision,
            );

            event(new WorkflowCancelled($updatedRun, 'Manual cancellation requested.'));

            return $updatedRun;
        });
    }

    private function requireRun(string $runId): WorkflowRunStateData
    {
        $run = $this->stateStore->get($runId);

        if ($run === null) {
            throw new RunNotFoundException($runId);
        }

        return $run;
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

    /**
     * @param  array<string, mixed>  $replacement
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
}
