<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class WorkflowRunStateData extends Data
{
    public function __construct(
        public string $id,
        public string $workflow,
        public int $workflow_version,
        public int $revision,
        public string $status,
        public CompiledWorkflowData $snapshot,
        public ?string $current_step_id = null,
        public array $input = [],
        public ?array $output = null,
        public array $context = [],
        public ?WaitStateData $wait = null,
        /** @var array<int, StepExecutionStateData> */
        #[DataCollectionOf(StepExecutionStateData::class)]
        public array $steps = [],
        /** @var array<int, TimelineEntryData> */
        #[DataCollectionOf(TimelineEntryData::class)]
        public array $timeline = [],
    ) {}
}
