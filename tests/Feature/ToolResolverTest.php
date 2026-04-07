<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Tests\Fixtures\Tools\NotATool;
use Entrepeneur4lyf\LaravelConductor\Tests\Fixtures\Tools\ReportGenerator;
use Entrepeneur4lyf\LaravelConductor\Tests\Fixtures\Tools\StockSnapshotTool;
use Entrepeneur4lyf\LaravelConductor\Tools\ToolResolver;

beforeEach(function (): void {
    // Point the convention-based resolver at the test fixture namespace
    // so "snake_case" identifiers land on the Tests\Fixtures\Tools\*
    // classes shipped for these tests.
    config()->set('conductor.tools.namespace', 'Entrepeneur4lyf\\LaravelConductor\\Tests\\Fixtures\\Tools');
    config()->set('conductor.tools.map', []);
});

it('resolves a tool from the explicit config map', function (): void {
    config()->set('conductor.tools.map', [
        'my_stock' => StockSnapshotTool::class,
    ]);

    $resolver = new ToolResolver;

    expect($resolver->resolve('my_stock'))->toBe(StockSnapshotTool::class);
});

it('resolves a fully qualified class name passed directly', function (): void {
    $resolver = new ToolResolver;

    expect($resolver->resolve(StockSnapshotTool::class))->toBe(StockSnapshotTool::class);
});

it('resolves a snake_case identifier via the configured namespace convention', function (): void {
    $resolver = new ToolResolver;

    expect($resolver->resolve('stock_snapshot'))->toBe(StockSnapshotTool::class);
});

it('resolves a snake_case identifier against a class that does not carry the Tool suffix', function (): void {
    $resolver = new ToolResolver;

    // ReportGenerator has no `Tool` suffix, so the resolver must try
    // {namespace}\ReportGeneratorTool (miss) then {namespace}\ReportGenerator (hit).
    expect($resolver->resolve('report_generator'))->toBe(ReportGenerator::class);
});

it('throws when the resolved class does not extend Atlas Tool', function (): void {
    config()->set('conductor.tools.map', [
        'bogus' => NotATool::class,
    ]);

    $resolver = new ToolResolver;

    expect(fn () => $resolver->resolve('bogus'))
        ->toThrow(RuntimeException::class);
});

it('throws when no resolution strategy succeeds', function (): void {
    $resolver = new ToolResolver;

    expect(fn () => $resolver->resolve('tool_that_does_not_exist_anywhere'))
        ->toThrow(RuntimeException::class);
});

it('allows runtime registration via register()', function (): void {
    $resolver = new ToolResolver;
    $resolver->register('runtime_stock', StockSnapshotTool::class);

    expect($resolver->resolve('runtime_stock'))->toBe(StockSnapshotTool::class);
});

it('register() rejects a class that does not extend Tool', function (): void {
    $resolver = new ToolResolver;

    expect(fn () => $resolver->register('bad', NotATool::class))
        ->toThrow(RuntimeException::class);
});

it('has() returns true when resolution succeeds and false otherwise', function (): void {
    $resolver = new ToolResolver;

    expect($resolver->has('stock_snapshot'))->toBeTrue()
        ->and($resolver->has('nothing_here'))->toBeFalse();
});

it('resolves multiple identifiers via resolveMany()', function (): void {
    config()->set('conductor.tools.map', [
        'alias' => StockSnapshotTool::class,
    ]);

    $resolver = new ToolResolver;

    $resolved = $resolver->resolveMany(['stock_snapshot', 'alias', StockSnapshotTool::class]);

    expect($resolved)->toBe([
        StockSnapshotTool::class,
        StockSnapshotTool::class,
        StockSnapshotTool::class,
    ]);
});
