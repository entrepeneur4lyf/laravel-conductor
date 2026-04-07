<?php

declare(strict_types=1);

/**
 * Negative-regression tripwires for definition fields that are accepted
 * by the loader/validator/compiler but not yet consumed at runtime. Each
 * test pins the *current* inert behavior so a silent semantic change
 * during a future F-task implementation is caught loudly.
 *
 * Remaining tripwires and the F-task that will flip each:
 *
 *   - parallel/foreach           → F11 (parallel fan-out)
 *   - step-level timeout         → F9  (per-step timeout enforcement)
 *
 * Flipped when the corresponding F-task shipped:
 *
 *   - tools, provider_tools      → F12 — see ToolResolverTest,
 *     ProviderToolResolverTest, AtlasStepExecutorToolsTest.
 *   - defaults merge             → F8  — see WorkflowDefaultsMergeTest.
 *   - on_fail transition         → F10 — see OnFailTransitionTest.
 *
 * When the corresponding F-task lands, the test assertion gets rewritten
 * to assert the NEW (active) behavior. These are tripwires, not permanent
 * guarantees.
 */

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Data\StepDefinitionData;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Engine\RunProcessor;

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

    $executor = new InertFieldRecordingExecutor;
    $this->app->instance(WorkflowStepExecutor::class, $executor);
    $this->app->forgetInstance(RunProcessor::class);

    $this->postJson("/api/conductor/runs/{$run->id}/continue")->assertOk();

    // A three-item foreach batch would result in 3 invocations if F11
    // were active. Today the supervisor and run processor do not inspect
    // parallel/foreach at all, so exactly one execution happens.
    expect($executor->invocations)->toBe(1);
});

// NOTE: The defaults-merge tripwires (F8 territory) were flipped to
// active assertions when F8 landed. They now live in
// WorkflowDefaultsMergeTest. The on_fail tripwire (F10) was also
// flipped — see OnFailTransitionTest. The F9 tripwire (timeout
// *enforcement* at execution time) will be added when F9 lands.
