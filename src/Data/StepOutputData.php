<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Data;

final class StepOutputData extends Data
{
    public function __construct(
        public string $step_id,
        public string $run_id,
        public string $status,
        public array $payload = [],
        public ?string $error = null,
        public array $metadata = [],
    ) {}
}
