<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class WorkflowDefinitionData extends Data
{
    public function __construct(
        public string $name,
        public int $version,
        /** @var array<int, StepDefinitionData> */
        #[DataCollectionOf(StepDefinitionData::class)]
        public array $steps,
        public ?string $description = null,
        /** @var array<int, FailureHandlerData> */
        #[DataCollectionOf(FailureHandlerData::class)]
        public array $failure_handlers = [],
        public array $defaults = [],
    ) {}
}
