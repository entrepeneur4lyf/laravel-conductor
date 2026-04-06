<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Contracts;

interface RunLockProvider
{
    public function acquire(string $runId, int $seconds = 15): bool;

    public function release(string $runId): void;
}
