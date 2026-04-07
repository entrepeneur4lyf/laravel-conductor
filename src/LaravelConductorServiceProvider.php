<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor;

use Atlasphp\Atlas\AgentRegistry;
use Entrepeneur4lyf\LaravelConductor\Agents\ConductorSupervisorAgent;
use Entrepeneur4lyf\LaravelConductor\Commands\CancelWorkflowCommand;
use Entrepeneur4lyf\LaravelConductor\Commands\MakeWorkflowCommand;
use Entrepeneur4lyf\LaravelConductor\Commands\RetryWorkflowCommand;
use Entrepeneur4lyf\LaravelConductor\Commands\ValidateWorkflowCommand;
use Entrepeneur4lyf\LaravelConductor\Commands\WorkflowStatusCommand;
use Entrepeneur4lyf\LaravelConductor\Contracts\DefinitionRepository;
use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Definitions\WorkflowCompiler;
use Entrepeneur4lyf\LaravelConductor\Definitions\WorkflowDefinitionValidator;
use Entrepeneur4lyf\LaravelConductor\Definitions\YamlWorkflowDefinitionRepository;
use Entrepeneur4lyf\LaravelConductor\Engine\EscalationEvaluator;
use Entrepeneur4lyf\LaravelConductor\Engine\RunProcessor;
use Entrepeneur4lyf\LaravelConductor\Engine\SchemaValidator;
use Entrepeneur4lyf\LaravelConductor\Engine\TemplateRenderer;
use Entrepeneur4lyf\LaravelConductor\Execution\AtlasStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Persistence\DatabaseWorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Persistence\OptimisticRunMutator;
use Entrepeneur4lyf\LaravelConductor\Support\CacheLockRunLockProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelConductorServiceProvider extends PackageServiceProvider
{
    public function registeringPackage(): void
    {
        $this->app->singleton(TemplateRenderer::class);
        $this->app->singleton(SchemaValidator::class);
        $this->app->singleton(EscalationEvaluator::class);
        $this->app->singleton(WorkflowDefinitionValidator::class);
        $this->app->singleton(WorkflowCompiler::class);
        $this->app->singleton(OptimisticRunMutator::class);
        $this->app->singleton(RunProcessor::class);
        $this->app->singleton(Conductor::class);
        $this->app->singleton(DatabaseWorkflowStateStore::class);
        $this->app->singleton(DefinitionRepository::class, YamlWorkflowDefinitionRepository::class);
        $this->app->singleton(RunLockProvider::class, CacheLockRunLockProvider::class);
        $this->app->singleton(WorkflowStateStore::class, DatabaseWorkflowStateStore::class);
        $this->app->singleton(WorkflowStepExecutor::class, AtlasStepExecutor::class);
    }

    public function packageBooted(): void
    {
        if ($this->app->bound(AgentRegistry::class)) {
            $this->app->make(AgentRegistry::class)->register(ConductorSupervisorAgent::class);
        }
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-conductor')
            ->hasCommands([
                ValidateWorkflowCommand::class,
                MakeWorkflowCommand::class,
                WorkflowStatusCommand::class,
                RetryWorkflowCommand::class,
                CancelWorkflowCommand::class,
            ])
            ->hasConfigFile('conductor')
            ->hasRoute('api')
            ->hasMigration('create_pipeline_runs_table')
            ->hasMigration('create_step_runs_table');
    }
}
