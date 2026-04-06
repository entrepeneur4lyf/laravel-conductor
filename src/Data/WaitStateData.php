<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Data;

final class WaitStateData extends Data
{
    public function __construct(
        public string $wait_type,
        public string $resume_token,
        public ?string $timeout_at = null,
        public array $metadata = [],
    ) {
    }
}
