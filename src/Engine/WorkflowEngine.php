<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Engine;

use Entrepeneur4lyf\LaravelConductor\Contracts\DefinitionRepository;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\TimelineEntryData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Definitions\WorkflowCompiler;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowStarted;
use Illuminate\Support\Str;
use RuntimeException;

final class WorkflowEngine
{
    public function __construct(
        private readonly DefinitionRepository $definitions,
        private readonly WorkflowCompiler $compiler,
        private readonly WorkflowStateStore $stateStore,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function start(string $workflow, array $input = []): WorkflowRunStateData
    {
        $loaded = $this->definitions->load($workflow);
        $compiled = $this->compiler->compile($loaded);
        $firstStep = $compiled->steps[0] ?? null;

        if ($firstStep === null) {
            throw new RuntimeException(sprintf('Workflow [%s] has no executable steps.', $workflow));
        }

        $run = $this->stateStore->store(new WorkflowRunStateData(
            id: (string) Str::ulid(),
            workflow: $compiled->name,
            workflow_version: $compiled->version,
            revision: 1,
            status: 'initializing',
            snapshot: $compiled,
            current_step_id: $firstStep->id,
            input: $input,
            steps: [
                new StepExecutionStateData(
                    step_definition_id: $firstStep->id,
                    status: 'pending',
                    attempt: 1,
                ),
            ],
            timeline: [
                new TimelineEntryData(
                    type: 'workflow_started',
                    message: 'Workflow initialized.',
                    context: [
                        'workflow' => $compiled->name,
                        'current_step_id' => $firstStep->id,
                    ],
                    occurred_at: now('UTC')->toIso8601String(),
                ),
            ],
        ));

        event(new WorkflowStarted($run));

        return $run;
    }
}
