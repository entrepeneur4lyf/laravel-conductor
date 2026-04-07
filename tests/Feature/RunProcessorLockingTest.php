<?php

declare(strict_types=1);

use Atlasphp\Atlas\Agent;
use Atlasphp\Atlas\AgentRegistry;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Enums\Provider;
use Atlasphp\Atlas\Responses\Usage;
use Atlasphp\Atlas\Testing\StructuredResponseFake;
use Entrepeneur4lyf\LaravelConductor\Conductor;
use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\RunProcessor;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunLockedException;

class RunProcessorLockingAgent extends Agent
{
    public function key(): string
    {
        return 'run-processor-locking-agent';
    }

    public function provider(): Provider|string|null
    {
        return Provider::OpenAI;
    }

    public function model(): ?string
    {
        return 'gpt-4o-mini';
    }
}

/**
 * Recording lock provider double — invokes the callback (so the run still
 * makes progress) while exposing how many times withLock was called and the
 * order in which the lock was held vs the callback executed.
 */
class RecordingRunLockProvider implements RunLockProvider
{
    /** @var array<int, string> */
    public array $events = [];

    public int $calls = 0;

    public function withLock(string $runId, Closure $callback, int $blockSeconds = 5): mixed
    {
        $this->calls++;
        $this->events[] = 'lock_acquired:'.$runId;

        try {
            $result = $callback();
            $this->events[] = 'callback_completed:'.$runId;

            return $result;
        } finally {
            $this->events[] = 'lock_released:'.$runId;
        }
    }
}

class ThrowingRunLockProvider implements RunLockProvider
{
    public int $calls = 0;

    public function withLock(string $runId, Closure $callback, int $blockSeconds = 5): mixed
    {
        $this->calls++;

        throw new RunLockedException($runId);
    }
}

class RecordingWorkflowStepExecutor implements WorkflowStepExecutor
{
    public int $invocations = 0;

    public function execute(string $agentKey, StepInputData $input): StepOutputData
    {
        $this->invocations++;

        return StepOutputData::from([
            'step_id' => $input->step_id,
            'run_id' => $input->run_id,
            'status' => 'completed',
            'payload' => ['ok' => true],
        ]);
    }
}

/**
 * Decorating state store that simulates a concurrent process advancing the
 * run between RunProcessor::persistRunning and the executor call. After the
 * inner store has observed at least one save() (which is what
 * persistRunning triggers), the very next get() returns a state with the
 * revision artificially incremented — without actually persisting the
 * mutation. That faithfully mirrors the race we are defending against:
 * "the version we're holding locally is stale because another worker
 * already moved past us." The pre-Atlas re-check inside RunProcessor will
 * see the divergence and throw RunRevisionMismatchException, never
 * reaching the executor.
 */
class RevisionAdvancingStateStore implements WorkflowStateStore
{
    public int $saveCalls = 0;

    public bool $advanceFired = false;

    public function __construct(private WorkflowStateStore $inner) {}

    public function store(WorkflowRunStateData $state): WorkflowRunStateData
    {
        return $this->inner->store($state);
    }

    public function get(string $runId): ?WorkflowRunStateData
    {
        $run = $this->inner->get($runId);

        if ($run === null) {
            return null;
        }

        if ($this->saveCalls > 0 && ! $this->advanceFired) {
            $this->advanceFired = true;

            return WorkflowRunStateData::from([
                ...$run->toArray(),
                'revision' => $run->revision + 1,
            ]);
        }

        return $run;
    }

    public function save(WorkflowRunStateData $state, int $expectedRevision): WorkflowRunStateData
    {
        $this->saveCalls++;

        return $this->inner->save($state, $expectedRevision);
    }
}

function bootRunProcessorLockingWorkflow(): string
{
    app(AgentRegistry::class)->register(RunProcessorLockingAgent::class);

    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'conductor-locking-'.bin2hex(random_bytes(5));
    $promptDirectory = $directory.DIRECTORY_SEPARATOR.'prompts';
    $schemaDirectory = $directory.DIRECTORY_SEPARATOR.'schemas';

    mkdir($promptDirectory, 0777, true);
    mkdir($schemaDirectory, 0777, true);

    file_put_contents($directory.DIRECTORY_SEPARATOR.'locking-workflow.yaml', <<<'YAML'
name: locking-workflow
version: 1
description: Locking workflow test
steps:
  - id: draft
    agent: run-processor-locking-agent
    prompt_template: prompts/draft.md.j2
    output_schema: "@schemas/draft-output.json"
    retries: 1
    timeout: 60
    on_success: complete
failure_handlers: []
YAML);

    file_put_contents($promptDirectory.DIRECTORY_SEPARATOR.'draft.md.j2', 'Write a draft about {{ topic }}.');
    file_put_contents($schemaDirectory.DIRECTORY_SEPARATOR.'draft-output.json', json_encode([
        'type' => 'object',
        'required' => ['headline'],
        'properties' => [
            'headline' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ], JSON_THROW_ON_ERROR));

    config()->set('conductor.definitions.paths', [$directory]);

    return $directory;
}

it('it wraps continueRun in a lock', function (): void {
    bootRunProcessorLockingWorkflow();

    Atlas::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'headline' => 'Locked draft',
            ])
            ->withUsage(new Usage(3, 4)),
    ]);

    $recorder = new RecordingRunLockProvider;
    $this->app->instance(RunLockProvider::class, $recorder);

    // Re-bind RunProcessor so it picks up the freshly bound lock provider.
    $this->app->forgetInstance(RunProcessor::class);

    $started = $this->postJson('/api/conductor/start', [
        'workflow' => 'locking-workflow',
        'input' => [
            'topic' => 'Locks',
        ],
    ]);

    $runId = (string) $started->json('data.id');

    $continued = $this->postJson("/api/conductor/runs/{$runId}/continue");

    $continued->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect($recorder->calls)->toBe(1)
        ->and($recorder->events)->toBe([
            'lock_acquired:'.$runId,
            'callback_completed:'.$runId,
            'lock_released:'.$runId,
        ]);
});

it('it returns 423 when /continue cannot acquire the lock', function (): void {
    bootRunProcessorLockingWorkflow();

    Atlas::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'headline' => 'Locked draft',
            ])
            ->withUsage(new Usage(3, 4)),
    ]);

    $started = $this->postJson('/api/conductor/start', [
        'workflow' => 'locking-workflow',
        'input' => [
            'topic' => 'Locks',
        ],
    ]);

    $runId = (string) $started->json('data.id');

    // Swap in a throwing lock provider AFTER start so the start path
    // (which does not lock) still succeeds normally.
    $thrower = new ThrowingRunLockProvider;
    $this->app->instance(RunLockProvider::class, $thrower);
    $this->app->forgetInstance(RunProcessor::class);

    $response = $this->postJson("/api/conductor/runs/{$runId}/continue");

    $response->assertStatus(423)
        ->assertJsonPath('message', 'Run is currently locked by another request.');

    expect($thrower->calls)->toBe(1);
});

it('it does not call the executor when the lock cannot be acquired', function (): void {
    bootRunProcessorLockingWorkflow();

    Atlas::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'headline' => 'Locked draft',
            ])
            ->withUsage(new Usage(3, 4)),
    ]);

    $started = $this->postJson('/api/conductor/start', [
        'workflow' => 'locking-workflow',
        'input' => [
            'topic' => 'Locks',
        ],
    ]);

    $runId = (string) $started->json('data.id');

    $executor = new RecordingWorkflowStepExecutor;
    $this->app->instance(WorkflowStepExecutor::class, $executor);

    $thrower = new ThrowingRunLockProvider;
    $this->app->instance(RunLockProvider::class, $thrower);
    $this->app->forgetInstance(RunProcessor::class);

    $response = $this->postJson("/api/conductor/runs/{$runId}/continue");

    $response->assertStatus(423);

    expect($executor->invocations)->toBe(0)
        ->and($thrower->calls)->toBe(1);
});

it('it rejects continueRun with 409 when the run revision advances between the lock acquire and the Atlas call', function (): void {
    bootRunProcessorLockingWorkflow();

    Atlas::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'headline' => 'Locked draft',
            ])
            ->withUsage(new Usage(3, 4)),
    ]);

    // Start the run with the real (un-decorated) state store so the
    // pipeline_runs row is persisted normally.
    $started = $this->postJson('/api/conductor/start', [
        'workflow' => 'locking-workflow',
        'input' => [
            'topic' => 'Locks',
        ],
    ]);

    $runId = (string) $started->json('data.id');

    // Wrap the bound state store with a decorator that advances the
    // revision on the first get() that happens after a save() — exactly
    // the position where RunProcessor performs its pre-Atlas re-check.
    $innerStore = $this->app->make(WorkflowStateStore::class);
    $advancingStore = new RevisionAdvancingStateStore($innerStore);
    $this->app->instance(WorkflowStateStore::class, $advancingStore);

    // Force RunProcessor, Conductor, and Supervisor to be re-resolved so
    // they pick up the freshly bound (decorated) state store. They are
    // singletons in the service provider, so without this they would
    // still hold a reference to the original instance.
    $this->app->forgetInstance(RunProcessor::class);
    $this->app->forgetInstance(Conductor::class);
    $this->app->forgetInstance(Supervisor::class);

    // Bind a recording executor so we can assert it is never reached.
    $executor = new RecordingWorkflowStepExecutor;
    $this->app->instance(WorkflowStepExecutor::class, $executor);

    $response = $this->postJson("/api/conductor/runs/{$runId}/continue");

    $response->assertStatus(409)
        ->assertJsonPath('message', 'Run state advanced while your request was processing. Reload and retry.');

    expect($executor->invocations)->toBe(0)
        ->and($advancingStore->advanceFired)->toBeTrue();
});
