<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Support;

use Entrepeneur4lyf\LaravelConductor\Data\TimelineEntryData;

final class Timeline
{
    /**
     * @param  array<int, TimelineEntryData>  $timeline
     * @param  array<string, mixed>  $context
     * @return array<int, TimelineEntryData>
     */
    public static function append(array $timeline, string $type, string $message, array $context = []): array
    {
        $timeline[] = new TimelineEntryData(
            type: $type,
            message: $message,
            context: $context,
            occurred_at: now('UTC')->toIso8601String(),
        );

        return $timeline;
    }
}
