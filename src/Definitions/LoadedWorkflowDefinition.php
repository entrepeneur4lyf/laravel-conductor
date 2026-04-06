<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Definitions;

use Entrepeneur4lyf\LaravelConductor\Data\WorkflowDefinitionData;

final readonly class LoadedWorkflowDefinition
{
    public function __construct(
        public WorkflowDefinitionData $definition,
        public string $sourcePath,
    ) {
    }
}
