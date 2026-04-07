<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Data;

use Spatie\LaravelData\Data;

final class TimelineEntryData extends Data
{
    public function __construct(
        public string $type,
        public string $message,
        public array $context = [],
        public ?string $occurred_at = null,
    ) {}
}
