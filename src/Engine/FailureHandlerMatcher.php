<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Engine;

use Entrepeneur4lyf\LaravelConductor\Data\FailureHandlerData;

final class FailureHandlerMatcher
{
    /**
     * @param  array<int, FailureHandlerData>  $handlers
     */
    public function match(array $handlers, string $error): ?FailureHandlerData
    {
        foreach ($handlers as $handler) {
            if (@preg_match('/'.$handler->match.'/i', $error) === 1) {
                return $handler;
            }
        }

        return null;
    }
}
