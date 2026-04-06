<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Data;

final class StepDefinitionData extends Data
{
    public function __construct(
        public string $id,
        public string $agent,
        public ?string $prompt_template = null,
        public ?string $output_schema = null,
        public ?string $prompt_template_path = null,
        public ?string $prompt_template_contents = null,
        public ?string $output_schema_path = null,
        public ?string $output_schema_contents = null,
        public ?string $wait_for = null,
        public array $context_map = [],
        public bool $parallel = false,
        public ?string $foreach = null,
        public int $retries = 0,
        public int $timeout = 120,
        public string $on_success = 'complete',
        public ?string $on_fail = null,
        public ?string $condition = null,
        public ?array $quality_rules = null,
        public array $tools = [],
        public array $provider_tools = [],
        public array $meta = [],
    ) {
    }
}
