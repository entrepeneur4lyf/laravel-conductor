<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Contracts;

use Entrepeneur4lyf\LaravelConductor\Definitions\LoadedWorkflowDefinition;

interface DefinitionRepository
{
    public function load(string $workflow): LoadedWorkflowDefinition;
}
