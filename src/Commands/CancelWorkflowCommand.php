<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Commands;

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowCancelled;
use Illuminate\Console\Command;

final class CancelWorkflowCommand extends Command
{
    protected $signature = 'conductor:cancel {runId : Workflow run identifier} {--revision= : Expected dossier revision}';

    protected $description = 'Cancel an active workflow run.';

    public function handle(WorkflowStateStore $stateStore): int
    {
        $run = $stateStore->get((string) $this->argument('runId'));

        if ($run === null) {
            $this->error('Workflow run not found.');

            return self::FAILURE;
        }

        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            $this->error('Workflow run is not eligible for cancellation.');

            return self::FAILURE;
        }

        try {
            $updatedRun = $stateStore->save(
                WorkflowRunStateData::from([
                    ...$run->toArray(),
                    'revision' => $run->revision + 1,
                    'status' => 'cancelled',
                    'current_step_id' => null,
                    'wait' => null,
                ]),
                $this->expectedRevision($run->revision),
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        event(new WorkflowCancelled($updatedRun, 'Manual cancellation requested.'));

        $this->info(sprintf('Cancelled workflow run [%s].', $updatedRun->id));

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
}
