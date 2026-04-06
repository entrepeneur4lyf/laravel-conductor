<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Commands;

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Illuminate\Console\Command;

final class WorkflowStatusCommand extends Command
{
    protected $signature = 'conductor:status {runId : Workflow run identifier}';

    protected $description = 'Show the current workflow dossier for a run.';

    public function handle(WorkflowStateStore $stateStore): int
    {
        $run = $stateStore->get((string) $this->argument('runId'));

        if ($run === null) {
            $this->error('Workflow run not found.');

            return self::FAILURE;
        }

        $waitType = $run->wait === null ? 'none' : $run->wait->wait_type;
        $resumeToken = $run->wait === null ? 'n/a' : $run->wait->resume_token;

        $this->table(
            ['Field', 'Value'],
            [
                ['Run ID', $run->id],
                ['Workflow', $run->workflow],
                ['Status', $run->status],
                ['Revision', (string) $run->revision],
                ['Current step', $run->current_step_id ?? 'none'],
                ['Wait type', $waitType],
                ['Resume token', $resumeToken],
            ],
        );

        return self::SUCCESS;
    }
}
