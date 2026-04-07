<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Exceptions;

use RuntimeException;
use Throwable;

final class CurrentStepNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $runId,
        public readonly string $stepId,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Current step [%s] for run [%s] was not found.', $stepId, $runId),
            0,
            $previous,
        );
    }
}
