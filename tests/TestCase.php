<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Tests;

use Atlasphp\Atlas\AtlasServiceProvider;
use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;
use Entrepeneur4lyf\LaravelConductor\LaravelConductorServiceProvider;
use Entrepeneur4lyf\LaravelConductor\Support\NullRunLockProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AtlasServiceProvider::class,
            LaravelConductorServiceProvider::class,
            LaravelDataServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Replace the default run lock provider with the null implementation
        // so feature tests stay deterministic and do not depend on a shared
        // cache backend. Individual tests can rebind this to exercise the
        // locking path explicitly.
        $app->bind(RunLockProvider::class, NullRunLockProvider::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareConductorDatabase();
    }

    private function prepareConductorDatabase(): void
    {
        Schema::dropIfExists('step_runs');
        Schema::dropIfExists('pipeline_runs');

        Schema::create('pipeline_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('workflow');
            $table->unsignedInteger('workflow_version');
            $table->unsignedInteger('revision')->default(1);
            $table->string('status');
            $table->string('current_step_id')->nullable();
            $table->json('input');
            $table->json('snapshot');
            $table->json('wait')->nullable();
            $table->string('retry_after')->nullable();
            $table->json('output')->nullable();
            $table->json('context')->nullable();
            $table->json('timeline')->nullable();
            $table->timestamps();

            $table->index('workflow');
            $table->index('status');
            $table->index(['workflow', 'status']);
        });

        Schema::create('step_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('pipeline_run_id');
            $table->string('step_definition_id');
            $table->string('status');
            $table->unsignedInteger('attempt')->default(1);
            $table->integer('batch_index')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->text('prompt_override')->nullable();
            $table->json('supervisor_decision')->nullable();
            $table->text('supervisor_feedback')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_run_id')
                ->references('id')
                ->on('pipeline_runs')
                ->cascadeOnDelete();

            $table->index('pipeline_run_id');
            $table->index(['pipeline_run_id', 'step_definition_id']);
            $table->index('status');
            $table->unique(['pipeline_run_id', 'step_definition_id', 'attempt', 'batch_index'], 'step_runs_identity_unique');
        });
    }
}
