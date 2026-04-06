<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Persistence;

use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\TimelineEntryData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Persistence\Models\PipelineRun;
use Entrepeneur4lyf\LaravelConductor\Persistence\Models\StepRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OptimisticRunMutator
{
    public function create(WorkflowRunStateData $state): PipelineRun
    {
        $this->assertInitialRevision($state);

        return DB::transaction(function () use ($state): PipelineRun {
            $run = PipelineRun::query()->create($this->pipelinePayload($state));
            $this->syncStepRuns($run, $state->steps);

            return $run->load('stepRuns');
        });
    }

    public function update(WorkflowRunStateData $state, int $expectedRevision): PipelineRun
    {
        $this->assertNextRevision($state, $expectedRevision);

        return DB::transaction(function () use ($state, $expectedRevision): PipelineRun {
            $payload = $this->pipelinePayload($state);
            unset($payload['id']);
            $payload['updated_at'] = now();
            $payload['revision'] = $state->revision;

            $updated = PipelineRun::query()
                ->whereKey($state->id)
                ->where('revision', $expectedRevision)
                ->update($payload);

            if ($updated !== 1) {
                throw new RuntimeException(sprintf(
                    'Unable to persist workflow dossier [%s] because revision [%d] is stale.',
                    $state->id,
                    $expectedRevision,
                ));
            }

            $run = PipelineRun::query()->findOrFail($state->id);
            $this->syncStepRuns($run, $state->steps);

            return $run->load('stepRuns');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelinePayload(WorkflowRunStateData $state): array
    {
        return [
            'id' => $state->id,
            'workflow' => $state->workflow,
            'workflow_version' => $state->workflow_version,
            'revision' => $state->revision,
            'status' => $state->status,
            'current_step_id' => $state->current_step_id,
            'input' => $state->input,
            'snapshot' => $state->snapshot->toArray(),
            'wait' => $state->wait?->toArray(),
            'output' => $state->output,
            'context' => $state->context,
            'timeline' => array_map(
                static fn (TimelineEntryData $entry): array => $entry->toArray(),
                $state->timeline,
            ),
        ];
    }

    /**
     * @param  array<int, StepExecutionStateData>  $steps
     */
    private function syncStepRuns(PipelineRun $run, array $steps): void
    {
        foreach ($steps as $step) {
            StepRun::query()->updateOrCreate(
                [
                    'pipeline_run_id' => $run->id,
                    'step_definition_id' => $step->step_definition_id,
                    'attempt' => $step->attempt,
                    'batch_index' => $step->batch_index,
                ],
                [
                    'status' => $step->status,
                    'input' => $step->input?->toArray(),
                    'output' => $step->output?->toArray(),
                    'error' => $step->error,
                    'prompt_override' => $step->prompt_override,
                    'supervisor_decision' => $step->supervisor_decision?->toArray(),
                    'supervisor_feedback' => $step->supervisor_feedback,
                ],
            );
        }
    }

    private function assertInitialRevision(WorkflowRunStateData $state): void
    {
        if ($state->revision !== 1) {
            throw new RuntimeException(sprintf(
                'Workflow dossier [%s] must initialize with revision 1.',
                $state->id,
            ));
        }
    }

    private function assertNextRevision(WorkflowRunStateData $state, int $expectedRevision): void
    {
        if ($state->revision !== $expectedRevision + 1) {
            throw new RuntimeException(sprintf(
                'Workflow dossier [%s] must advance from revision [%d] to [%d].',
                $state->id,
                $expectedRevision,
                $expectedRevision + 1,
            ));
        }
    }
}
