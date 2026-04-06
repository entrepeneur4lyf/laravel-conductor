<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Data;

final class StepExecutionStateData extends Data
{
    public function __construct(
        public string $step_definition_id,
        public string $status,
        public int $attempt = 1,
        public ?int $batch_index = null,
        public ?StepInputData $input = null,
        public ?StepOutputData $output = null,
        public ?string $error = null,
        public ?SupervisorDecisionData $supervisor_decision = null,
        public ?string $prompt_override = null,
        public ?string $supervisor_feedback = null,
        public ?string $completed_at = null,
    ) {
    }
}
