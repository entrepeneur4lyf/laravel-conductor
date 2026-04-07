<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Exceptions;

use RuntimeException;
use Throwable;

final class InvalidResumeTokenException extends RuntimeException
{
    public function __construct(public readonly string $runId, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Invalid resume token for run [%s].', $runId),
            0,
            $previous,
        );
    }
}
