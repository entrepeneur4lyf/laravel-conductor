<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Tests\Fixtures\Tools;

/**
 * A class that does NOT extend Atlasphp\Atlas\Tools\Tool. Used by
 * ToolResolverTest to verify the resolver rejects non-Tool classes.
 */
final class NotATool
{
    public function name(): string
    {
        return 'not_a_tool';
    }
}
