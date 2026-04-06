<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Data;

final class SupervisorDecisionData extends Data
{
    public function __construct(
        public string $action,
        public ?string $next_step_id = null,
        public ?string $reason = null,
        public ?string $modified_prompt = null,
        public ?float $confidence = null,
        public ?int $delay = null,
    ) {
    }
}
