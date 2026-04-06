<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Events;

use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;

final class WorkflowFailed
{
    public function __construct(
        public readonly WorkflowRunStateData $run,
        public readonly string $stepId,
        public readonly string $reason,
    ) {
    }
}
