<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Contracts;

use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;

interface WorkflowStateStore
{
    public function store(WorkflowRunStateData $state): WorkflowRunStateData;

    public function get(string $runId): ?WorkflowRunStateData;

    public function save(WorkflowRunStateData $state, int $expectedRevision): WorkflowRunStateData;
}
