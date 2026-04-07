<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Contracts;

use Closure;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunLockedException;

interface RunLockProvider
{
    /**
     * Run the callback while holding an exclusive lock for the given run.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws RunLockedException if the lock cannot be acquired within $blockSeconds.
     */
    public function withLock(string $runId, Closure $callback, int $blockSeconds = 5): mixed;
}
