<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Support;

use Closure;
use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;

final class NullRunLockProvider implements RunLockProvider
{
    public function withLock(string $runId, Closure $callback, int $blockSeconds = 5): mixed
    {
        return $callback();
    }
}
