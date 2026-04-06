<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class CompiledWorkflowData extends Data
{
    public function __construct(
        public string $name,
        public int $version,
        public string $compiled_at,
        public string $source_hash,
        /** @var array<int, StepDefinitionData> Frozen step snapshots with resolved paths and execution-critical asset contents. */
        #[DataCollectionOf(StepDefinitionData::class)]
        public array $steps = [],
        /** @var array<int, FailureHandlerData> */
        #[DataCollectionOf(FailureHandlerData::class)]
        public array $failure_handlers = [],
        public array $defaults = [],
        public ?string $description = null,
    ) {
    }
}
