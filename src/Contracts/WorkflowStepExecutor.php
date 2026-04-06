<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Contracts;

use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;

interface WorkflowStepExecutor
{
    public function execute(string $agent, StepInputData $input): StepOutputData;
}
