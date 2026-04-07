<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Exceptions;

use RuntimeException;
use Throwable;

final class RunLockedException extends RuntimeException
{
    public function __construct(public readonly string $runId, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Workflow run [%s] is locked by another in-flight request.', $runId),
            0,
            $previous,
        );
    }
}
