<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Persistence;

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Persistence\Models\PipelineRun;
use Entrepeneur4lyf\LaravelConductor\Persistence\Models\StepRun;

final class DatabaseWorkflowStateStore implements WorkflowStateStore
{
    public function __construct(
        private readonly OptimisticRunMutator $mutator,
    ) {
    }

    public function store(WorkflowRunStateData $state): WorkflowRunStateData
    {
        return $this->hydrate($this->mutator->create($state));
    }

    public function get(string $runId): ?WorkflowRunStateData
    {
        $run = PipelineRun::query()
            ->with('stepRuns')
            ->find($runId);

        if ($run === null) {
            return null;
        }

        return $this->hydrate($run);
    }

    public function save(WorkflowRunStateData $state, int $expectedRevision): WorkflowRunStateData
    {
        return $this->hydrate($this->mutator->update($state, $expectedRevision));
    }

    private function hydrate(PipelineRun $run): WorkflowRunStateData
    {
        return WorkflowRunStateData::from([
            'id' => $run->id,
            'workflow' => $run->workflow,
            'workflow_version' => $run->workflow_version,
            'revision' => $run->revision,
            'status' => $run->status,
            'current_step_id' => $run->current_step_id,
            'input' => $run->input ?? [],
            'output' => $run->output,
            'context' => $run->context ?? [],
            'snapshot' => $run->snapshot ?? [],
            'wait' => $run->wait,
            'steps' => $run->stepRuns->map(static function (StepRun $stepRun): array {
                return [
                    'step_definition_id' => $stepRun->step_definition_id,
                    'status' => $stepRun->status,
                    'attempt' => $stepRun->attempt,
                    'batch_index' => $stepRun->batch_index,
                    'input' => $stepRun->input,
                    'output' => $stepRun->output,
                    'error' => $stepRun->error,
                    'prompt_override' => $stepRun->prompt_override,
                    'supervisor_decision' => $stepRun->supervisor_decision,
                    'supervisor_feedback' => $stepRun->supervisor_feedback,
                ];
            })->all(),
            'timeline' => $run->timeline ?? [],
        ]);
    }
}
