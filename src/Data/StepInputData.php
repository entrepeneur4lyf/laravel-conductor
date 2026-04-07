<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Data;

final class StepInputData extends Data
{
    public function __construct(
        public string $step_id,
        public string $run_id,
        public string $rendered_prompt,
        public array $payload = [],
        public ?StepOutputData $previous_output = null,
        public array $meta = [],
    ) {}
}
