<?php

declare(strict_types=1);

use Atlasphp\Atlas\Providers\Tools\CodeExecution;
use Atlasphp\Atlas\Providers\Tools\CodeInterpreter;
use Atlasphp\Atlas\Providers\Tools\FileSearch;
use Atlasphp\Atlas\Providers\Tools\GoogleSearch;
use Atlasphp\Atlas\Providers\Tools\WebFetch;
use Atlasphp\Atlas\Providers\Tools\WebSearch;
use Atlasphp\Atlas\Providers\Tools\XSearch;
use Entrepeneur4lyf\LaravelConductor\Tools\ProviderToolResolver;

it('resolves a bare string declaration to the matching ProviderTool class', function (): void {
    $resolver = new ProviderToolResolver;

    expect($resolver->resolve('web_search'))->toBeInstanceOf(WebSearch::class);
});

it('resolves all known provider tool types', function (string $type, string $class): void {
    $resolver = new ProviderToolResolver;

    expect($resolver->resolve($type))->toBeInstanceOf($class);
})->with([
    ['web_search', WebSearch::class],
    ['web_fetch', WebFetch::class],
    ['file_search', FileSearch::class],
    ['code_interpreter', CodeInterpreter::class],
    ['google_search', GoogleSearch::class],
    ['code_execution', CodeExecution::class],
    ['x_search', XSearch::class],
]);

it('passes snake_case option keys through to constructor params in camelCase', function (): void {
    $resolver = new ProviderToolResolver;

    $tool = $resolver->resolve([
        'type' => 'web_search',
        'max_results' => 5,
        'locale' => 'en-US',
    ]);

    // WebSearch exposes its config via ->config(). snake_case keys in
    // that output mean the constructor args landed in the right places.
    expect($tool)->toBeInstanceOf(WebSearch::class)
        ->and($tool->config())->toBe([
            'max_results' => 5,
            'locale' => 'en-US',
        ]);
});

it('normalizes kebab-case and whitespace variants of the type alias', function (string $alias): void {
    $resolver = new ProviderToolResolver;

    expect($resolver->resolve($alias))->toBeInstanceOf(WebSearch::class);
})->with(['web_search', 'web-search', 'web search', 'WEB_SEARCH']);

it('accepts an FQCN passthrough for custom provider tools', function (): void {
    $resolver = new ProviderToolResolver;

    expect($resolver->resolve(WebSearch::class))->toBeInstanceOf(WebSearch::class);
});

it('throws on an unknown provider tool type', function (): void {
    $resolver = new ProviderToolResolver;

    expect(fn () => $resolver->resolve('definitely_not_a_real_tool'))
        ->toThrow(RuntimeException::class);
});

it('throws when an object declaration is missing the type key', function (): void {
    $resolver = new ProviderToolResolver;

    expect(fn () => $resolver->resolve(['max_results' => 5]))
        ->toThrow(RuntimeException::class);
});

it('resolves multiple declarations via resolveMany()', function (): void {
    $resolver = new ProviderToolResolver;

    $resolved = $resolver->resolveMany([
        'web_search',
        ['type' => 'web_fetch'],
        'file_search',
    ]);

    expect($resolved)->toHaveCount(3)
        ->and($resolved[0])->toBeInstanceOf(WebSearch::class)
        ->and($resolved[1])->toBeInstanceOf(WebFetch::class)
        ->and($resolved[2])->toBeInstanceOf(FileSearch::class);
});
