<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Entrepeneur4lyf\LaravelConductor\Conductor
 */
final class Conductor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Entrepeneur4lyf\LaravelConductor\Conductor::class;
    }
}
