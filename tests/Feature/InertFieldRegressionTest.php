<?php

declare(strict_types=1);

/**
 * Negative-regression tripwires for definition fields that are accepted
 * by the loader/validator/compiler but not yet consumed at runtime. Each
 * test pins the *current* inert behavior so a silent semantic change
 * during a future F-task implementation is caught loudly.
 *
 * Mapping of tests to the F-tasks that will flip them:
 *
 *   - parallel/foreach           → F11 (parallel fan-out)
 *   - tools                      → F12 (tool invocation)
 *   - provider_tools             → F12 (provider tool invocation)
 *   - on_fail transition         → F10 (on_fail consumption)
 *   - defaults.timeout           → F9  (timeout enforcement)
 *   - defaults merge             → F8  (defaults merge at compile time)
 *
 * When the corresponding F-task lands, the test assertion gets rewritten
 * to assert the NEW (active) behavior. These are tripwires, not permanent
 * guarantees.
 */

use Atlasphp\Atlas\Agent;
use Atlasphp\Atlas\AgentRegistry;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Enums\Provider;
use Atlasphp\Atlas\Requests\TextRequest;
use Atlasphp\Atlas\Responses\Usage;
use Atlasphp\Atlas\Testing\TextResponseFake;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Definitions\YamlWorkflowDefinitionRepository;
use Entrepeneur4lyf\LaravelConductor\Definitions\WorkflowCompiler;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;
use Entrepeneur4lyf\LaravelConductor\Execution\AtlasStepExecutor;

/**
 * Recording executor used to count invocations for the parallel/foreach
 * tripwire.
 */
class InertFieldRecordingExecutor implements WorkflowStepExecutor
{
    public int $invocations = 0;

    public function execute(string $agentKey, StepInputData $input): StepOutputData
    {
        $this->invocations++;

        return StepOutputData::from([
            'step_id' => $input->step_id,
            'run_id' => $input->run_id,
            'status' => 'completed',
            'payload' => ['headline' => 'recorded'],
        ]);
    }
}

class InertFieldAgent extends Agent
{
    public function key(): string
    {
        return 'inert-field-agent';
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

// ─── F11 tripwire: parallel / foreach ───────────────────────────────────

it('does not fan out parallel/foreach steps today (F11 tripwire)', function (): void {
    // TODO(F11): when parallel/foreach execution lands via
    // ParallelExecutionStrategy, rewrite this test to assert that a
    // foreach with N items results in N executor invocations (one per
    // batch_index) and that the final state reflects a successful
    // batch gather.

    $parallelStep = StepDefinitionData::from([
        'id' => 'draft',
        'agent' => 'writer',
        'prompt_template' => 'prompts/draft.md.j2',
        'prompt_template_contents' => 'Draft for {{ item }}.',
        'parallel' => true,
        'foreach' => '{{ items }}',
        'retries' => 0,
        'timeout' => 60,
        'on_success' => 'complete',
    ]);

    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [$parallelStep],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'pending',
                'attempt' => 1,
            ]),
        ],
        overrides: [
            'id' => 'run-inert-parallel',
            'status' => 'running',
            'current_step_id' => 'draft',
            'input' => [
                'items' => ['alpha', 'beta', 'gamma'],
            ],
        ],
    );

    $executor = new InertFieldRecordingExecutor();
    $this->app->instance(WorkflowStepExecutor::class, $executor);
    $this->app->forgetInstance(\Entrepeneur4lyf\LaravelConductor\Engine\RunProcessor::class);

    $this->postJson("/api/conductor/runs/{$run->id}/continue")->assertOk();

    // A three-item foreach batch would result in 3 invocations if F11
    // were active. Today the supervisor and run processor do not inspect
    // parallel/foreach at all, so exactly one execution happens.
    expect($executor->invocations)->toBe(1);
});

// ─── F12 tripwire: tools ────────────────────────────────────────────────

it('does not invoke step-level tools through Atlas today (F12 tripwire)', function (): void {
    // TODO(F12): when tool invocation lands, rewrite this test to
    // assert that declaring `tools: ['stock_snapshot']` on a step
    // causes the recorded Atlas TextRequest to carry the resolved
    // Tool class in its $tools array (and that an unknown tool
    // identifier causes the resolver to throw during execution).

    app(AgentRegistry::class)->register(InertFieldAgent::class);

    $fake = Atlas::fake([
        TextResponseFake::make()->withText('ok')->withUsage(new Usage(1, 1)),
    ]);

    $executor = app(WorkflowStepExecutor::class);
    expect($executor)->toBeInstanceOf(AtlasStepExecutor::class);

    $input = StepInputData::from([
        'step_id' => 'draft',
        'run_id' => 'run-inert-tools',
        'rendered_prompt' => 'Do something.',
        'payload' => [],
        'meta' => [
            'tools' => ['nonexistent_tool_that_would_fail_if_resolved'],
        ],
    ]);

    // Today, the executor silently ignores meta.tools. It does not
    // try to resolve `nonexistent_tool_...`, so execute() returns
    // normally without throwing.
    $executor->execute('inert-field-agent', $input);

    $recorded = $fake->recorded();

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]->request)->toBeInstanceOf(TextRequest::class)
        // The tripwire: the recorded request's $tools array is empty
        // because the executor never called ->withTools(...) despite
        // meta.tools being populated. When F12 lands this assertion
        // will fail and need to be flipped to assert presence.
        ->and($recorded[0]->request->tools)->toBe([]);
});

// ─── F12 tripwire: provider_tools ───────────────────────────────────────

it('does not invoke step-level provider_tools through Atlas today (F12 tripwire)', function (): void {
    // TODO(F12): rewrite to assert that declaring
    // `provider_tools: [{type: web_search, max_results: 5}]` causes the
    // recorded TextRequest to carry a resolved WebSearch instance in
    // its $providerTools array.

    app(AgentRegistry::class)->register(InertFieldAgent::class);

    $fake = Atlas::fake([
        TextResponseFake::make()->withText('ok')->withUsage(new Usage(1, 1)),
    ]);

    $executor = app(WorkflowStepExecutor::class);

    $input = StepInputData::from([
        'step_id' => 'draft',
        'run_id' => 'run-inert-provider-tools',
        'rendered_prompt' => 'Do something.',
        'payload' => [],
        'meta' => [
            'provider_tools' => [
                ['type' => 'web_search', 'max_results' => 5],
            ],
        ],
    ]);

    $executor->execute('inert-field-agent', $input);

    $recorded = $fake->recorded();

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]->request->providerTools)->toBe([]);
});

// ─── F10 tripwire: on_fail transition ───────────────────────────────────

it('does not consume per-step on_fail as a transition target today (F10 tripwire)', function (): void {
    // TODO(F10): when per-step on_fail consumption lands, rewrite this
    // test to assert that a failed step with `on_fail: cleanup`
    // transitions the run to the cleanup step after failure handlers
    // and escalation are exhausted.

    $stepWithOnFail = StepDefinitionData::from([
        'id' => 'draft',
        'agent' => 'writer',
        'prompt_template' => 'prompts/draft.md.j2',
        'prompt_template_contents' => 'Draft something.',
        'retries' => 0,
        'timeout' => 60,
        'on_success' => 'complete',
        'on_fail' => 'fail',
    ]);

    $run = storeRunState(
        workflow: makeCompiledWorkflow(
            steps: [$stepWithOnFail],
            failureHandlers: [],
        ),
        steps: [
            StepExecutionStateData::from([
                'step_definition_id' => 'draft',
                'status' => 'failed',
                'attempt' => 1,
                'error' => 'unhandled_error',
            ]),
        ],
        overrides: [
            'id' => 'run-inert-on-fail',
            'status' => 'running',
            'current_step_id' => 'draft',
        ],
    );

    // Supervisor evaluation of a failed step with no matching handler and
    // no retry budget remaining currently walks the fail path (because
    // retries=0 and attempt=1 means the retry budget is already
    // exhausted, and without an escalation match on_fail is also
    // untouched).
    $decision = app(Supervisor::class)->evaluate($run->id, 'draft');

    // Today: the supervisor returns `fail` and the run ends up in the
    // `failed` status. It never routes to the `on_fail` target.
    expect($decision->action)->toBe('fail');

    $stored = app(WorkflowStateStore::class)->get($run->id);
    expect($stored)->not->toBeNull()
        ->and($stored?->status)->toBe('failed');
});

// ─── F9 tripwire: defaults.timeout ──────────────────────────────────────

function writeInertTripwireWorkflow(string $yaml): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'conductor-inert-'.bin2hex(random_bytes(5));
    mkdir($directory, 0777, true);
    file_put_contents($directory.DIRECTORY_SEPARATOR.'tripwire.yaml', $yaml);
    config()->set('conductor.definitions.paths', [$directory]);

    return $directory;
}

it('does not enforce defaults.timeout at compile time today (F9 tripwire)', function (): void {
    // TODO(F9): when timeout enforcement lands (after F8 makes
    // defaults.timeout merge into individual steps), rewrite this test
    // to assert that a step without an explicit timeout inherits the
    // defaults value and that an exceeded timeout surfaces as a failed
    // step.

    writeInertTripwireWorkflow(<<<'YAML'
name: inert-tripwire
version: 1
description: Defaults tripwire
defaults:
  timeout: 30
steps:
  - id: draft
    agent: writer
    on_success: complete
YAML);

    $loaded = app(YamlWorkflowDefinitionRepository::class)->load('tripwire');
    $compiled = app(WorkflowCompiler::class)->compile($loaded);

    // Today, the compiled step's timeout is the DTO default (120s),
    // NOT the 30s declared in the defaults block. The defaults block
    // round-trips unchanged onto the compiled snapshot but is never
    // merged into the individual steps.
    expect($compiled->steps[0]->timeout)->toBe(120)
        ->and($compiled->defaults)->toHaveKey('timeout')
        ->and($compiled->defaults['timeout'])->toBe(30);
});

// ─── F8 tripwire: defaults merge ────────────────────────────────────────

it('does not merge defaults.retries into individual step compilation today (F8 tripwire)', function (): void {
    // TODO(F8): when defaults merge lands in WorkflowCompiler::compileStep,
    // rewrite this test to assert that a step without explicit retries
    // inherits the defaults value (expected: $compiled->steps[0]->retries === 5).

    writeInertTripwireWorkflow(<<<'YAML'
name: inert-tripwire
version: 1
description: Defaults retries tripwire
defaults:
  retries: 5
steps:
  - id: draft
    agent: writer
    on_success: complete
YAML);

    $loaded = app(YamlWorkflowDefinitionRepository::class)->load('tripwire');
    $compiled = app(WorkflowCompiler::class)->compile($loaded);

    // Today: compiled step inherits the DTO default of 0, not 5.
    expect($compiled->steps[0]->retries)->toBe(0)
        ->and($compiled->defaults)->toHaveKey('retries')
        ->and($compiled->defaults['retries'])->toBe(5);
});
