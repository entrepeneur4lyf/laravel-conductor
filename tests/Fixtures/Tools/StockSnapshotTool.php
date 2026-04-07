<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Tests\Fixtures\Tools;

use Atlasphp\Atlas\Tools\Tool;

/**
 * Convention-based tool fixture for ToolResolver tests. Lives at
 * Tests\Fixtures\Tools\StockSnapshotTool so a snake_case identifier
 * of `stock_snapshot` with namespace `Tests\Fixtures\Tools` resolves
 * to this class via the convention strategy.
 */
final class StockSnapshotTool extends Tool
{
    public function name(): string
    {
        return 'stock_snapshot';
    }

    public function description(): string
    {
        return 'Returns a fake stock snapshot for testing.';
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     */
    public function handle(array $args, array $context): mixed
    {
        return ['symbol' => 'TEST', 'price' => 100.0];
    }
}
