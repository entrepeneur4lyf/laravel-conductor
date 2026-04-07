<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Exceptions;

use RuntimeException;
use Throwable;

final class RunRevisionMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $runId,
        public readonly int $expected,
        public readonly int $actual,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Run [%s] revision mismatch: expected %d, got %d.', $runId, $expected, $actual),
            0,
            $previous,
        );
    }
}
