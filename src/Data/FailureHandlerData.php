<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Data;

final class FailureHandlerData extends Data
{
    public function __construct(
        public string $match,
        public string $action,
        public ?int $delay = null,
        public ?string $prompt_template = null,
        public ?string $prompt_template_path = null,
        public ?string $prompt_template_contents = null,
    ) {
    }
}
