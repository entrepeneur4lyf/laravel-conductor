<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Support;

use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;

final class NullRunLockProvider implements RunLockProvider
{
    public function acquire(string $runId, int $seconds = 15): bool
    {
        return true;
    }

    public function release(string $runId): void {}
}
