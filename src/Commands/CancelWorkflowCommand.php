<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Commands;

use Entrepeneur4lyf\LaravelConductor\Conductor;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunLockedException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotCancellableException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotFoundException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunRevisionMismatchException;
use Illuminate\Console\Command;
use Throwable;

final class CancelWorkflowCommand extends Command
{
    protected $signature = 'conductor:cancel {runId : Workflow run identifier} {--revision= : Expected dossier revision}';

    protected $description = 'Cancel an active workflow run.';

    public function handle(Conductor $conductor, WorkflowStateStore $stateStore): int
    {
        $runId = (string) $this->argument('runId');

        try {
            $updatedRun = $conductor->cancelRun(
                $runId,
                $this->expectedRevision($runId, $stateStore),
            );
        } catch (RunNotFoundException) {
            $this->error('Workflow run not found.');

            return self::FAILURE;
        } catch (RunNotCancellableException) {
            $this->error('Workflow run is not eligible for cancellation.');

            return self::FAILURE;
        } catch (RunRevisionMismatchException $exception) {
            $this->error(sprintf(
                'Run revision mismatch: expected %d, got %d.',
                $exception->expected,
                $exception->actual,
            ));

            return self::FAILURE;
        } catch (RunLockedException) {
            $this->error('Workflow run is currently locked by another in-flight request. Try again in a moment.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Cancelled workflow run [%s].', $updatedRun->id));

        return self::SUCCESS;
    }

    private function expectedRevision(string $runId, WorkflowStateStore $stateStore): int
    {
        $revision = $this->option('revision');

        if ($revision === null || $revision === '') {
            $run = $stateStore->get($runId);

            if ($run === null) {
                return 0;
            }

            return $run->revision;
        }

        return (int) $revision;
    }
}
