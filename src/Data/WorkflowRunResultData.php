<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Data;

final class WorkflowRunResultData extends Data
{
    public function __construct(
        public WorkflowRunStateData $run,
        public SupervisorDecisionData $decision,
    ) {}
}
