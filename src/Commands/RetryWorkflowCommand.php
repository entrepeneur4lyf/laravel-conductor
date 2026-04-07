<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Commands;

use Entrepeneur4lyf\LaravelConductor\Conductor;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Exceptions\CurrentStepNotFoundException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunLockedException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotFoundException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotRetryableException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunRevisionMismatchException;
use Illuminate\Console\Command;
use Throwable;

final class RetryWorkflowCommand extends Command
{
    protected $signature = 'conductor:retry {runId : Workflow run identifier} {--revision= : Expected dossier revision}';

    protected $description = 'Retry a failed workflow run by appending a new pending step attempt.';

    public function handle(Conductor $conductor, WorkflowStateStore $stateStore): int
    {
        $runId = (string) $this->argument('runId');

        try {
            $updatedRun = $conductor->retryRun(
                $runId,
                $this->expectedRevision($runId, $stateStore),
            );
        } catch (RunNotFoundException) {
            $this->error('Workflow run not found.');

            return self::FAILURE;
        } catch (CurrentStepNotFoundException) {
            $this->error('Current step could not be located.');

            return self::FAILURE;
        } catch (RunNotRetryableException) {
            $this->error('Workflow run is not eligible for retry.');

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

        $this->info(sprintf('Retried workflow run [%s].', $updatedRun->id));

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
