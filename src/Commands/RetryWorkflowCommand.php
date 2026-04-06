<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Commands;

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Events\StepRetrying;
use Illuminate\Console\Command;

final class RetryWorkflowCommand extends Command
{
    protected $signature = 'conductor:retry {runId : Workflow run identifier} {--revision= : Expected dossier revision}';

    protected $description = 'Retry a failed workflow run by appending a new pending step attempt.';

    public function handle(WorkflowStateStore $stateStore): int
    {
        $run = $stateStore->get((string) $this->argument('runId'));

        if ($run === null) {
            $this->error('Workflow run not found.');

            return self::FAILURE;
        }

        if ($run->status !== 'failed' || $run->current_step_id === null) {
            $this->error('Workflow run is not eligible for retry.');

            return self::FAILURE;
        }

        $expectedRevision = $this->expectedRevision($run->revision);
        $step = $this->latestStep($run, $run->current_step_id);

        if ($step === null) {
            $this->error('Current step could not be located.');

            return self::FAILURE;
        }

        try {
            $updatedRun = $stateStore->save(
                WorkflowRunStateData::from([
                    ...$run->toArray(),
                    'revision' => $run->revision + 1,
                    'status' => 'running',
                    'steps' => array_merge(
                        array_map(
                            static fn (StepExecutionStateData $existing): array => $existing->toArray(),
                            $run->steps,
                        ),
                        [
                            StepExecutionStateData::from([
                                'step_definition_id' => $run->current_step_id,
                                'status' => 'pending',
                                'attempt' => $step->attempt + 1,
                            ])->toArray(),
                        ],
                    ),
                ]),
                $expectedRevision,
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        event(new StepRetrying($updatedRun, $run->current_step_id, 'Manual retry requested.'));

        $this->info(sprintf('Retried workflow run [%s].', $updatedRun->id));

        return self::SUCCESS;
    }

    private function expectedRevision(int $currentRevision): int
    {
        $revision = $this->option('revision');

        if ($revision === null || $revision === '') {
            return $currentRevision;
        }

        return (int) $revision;
    }

    private function latestStep(WorkflowRunStateData $run, string $stepId): ?StepExecutionStateData
    {
        $matches = array_values(array_filter(
            $run->steps,
            static fn (StepExecutionStateData $step): bool => $step->step_definition_id === $stepId,
        ));

        if ($matches === []) {
            return null;
        }

        return $matches[array_key_last($matches)];
    }
}
