<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Engine;

use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\SupervisorDecisionData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;

final class IdempotencyGuard
{
    public function forEvaluation(
        WorkflowRunStateData $run,
        ?StepExecutionStateData $step,
        string $stepId,
    ): ?SupervisorDecisionData {
        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return $this->noop('Run is terminal.');
        }

        if ($run->current_step_id !== $stepId) {
            return $this->noop('Current step no longer matches the evaluation target.');
        }

        if ($step === null) {
            return $this->noop('Step execution state could not be found.');
        }

        if ($step->supervisor_decision !== null) {
            return $this->noop('Step already has a supervisor decision.');
        }

        return null;
    }

    public function forRetryAttempt(
        WorkflowRunStateData $run,
        ?StepExecutionStateData $step,
        string $stepId,
        int $attempt,
    ): bool {
        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return false;
        }

        if ($run->current_step_id !== $stepId || $step === null) {
            return false;
        }

        return $step->status === 'pending' && $step->attempt === $attempt;
    }

    private function noop(string $reason): SupervisorDecisionData
    {
        return new SupervisorDecisionData(
            action: 'noop',
            reason: $reason,
        );
    }
}
