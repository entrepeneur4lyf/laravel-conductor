<?php

use Entrepeneur4lyf\LaravelConductor\Conductor;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Facades\Conductor as ConductorFacade;
use Entrepeneur4lyf\LaravelConductor\LaravelConductorServiceProvider;
use Entrepeneur4lyf\LaravelConductor\Persistence\DatabaseWorkflowStateStore;

it('boots the conductor service provider', function (): void {
    expect(app()->getProviders(LaravelConductorServiceProvider::class))->not->toBeEmpty();
    expect(config('conductor'))->toBeArray();
    expect(config('conductor.state.driver'))->toBe('database');
    expect(app(Conductor::class))->toBeInstanceOf(Conductor::class);
    expect(ConductorFacade::getFacadeRoot())->toBeInstanceOf(Conductor::class);
    expect(app(WorkflowStateStore::class))->toBeInstanceOf(DatabaseWorkflowStateStore::class);
});
