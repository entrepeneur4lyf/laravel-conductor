<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Tests\Fixtures\Tools;

use Atlasphp\Atlas\Tools\Tool;

/**
 * Convention-alt tool fixture: name does not carry the `Tool` suffix
 * so the resolver must fall through to the alt-naming branch.
 */
final class ReportGenerator extends Tool
{
    public function name(): string
    {
        return 'report_generator';
    }

    public function description(): string
    {
        return 'Generates a fake report.';
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     */
    public function handle(array $args, array $context): mixed
    {
        return ['report' => 'empty'];
    }
}
