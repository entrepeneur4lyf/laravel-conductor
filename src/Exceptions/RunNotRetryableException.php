<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Exceptions;

use RuntimeException;
use Throwable;

final class RunNotRetryableException extends RuntimeException
{
    public function __construct(public readonly string $runId, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Run [%s] is not eligible for retry.', $runId),
            0,
            $previous,
        );
    }
}
