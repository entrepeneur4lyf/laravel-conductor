# F11 — Parallel / foreach fan-out execution

**Status:** Draft — 2026-04-07
**Scope:** Implement the `parallel: true` + `foreach: ...` step execution model as a standalone milestone, deferred from the Phase A→D remediation pass
**Depends on:** F8 (defaults merge), F10 (on_fail), F12 (tools) — all shipped
**Blocks:** Nothing on the remediation side. F11 is the last advertised-but-inert field.

---

## Goal

When a step declares `parallel: true` and a `foreach` expression, the runtime should:

1. Evaluate the `foreach` expression against the run context to obtain an ordered array of items.
2. Execute the step's agent **once per item**, each invocation seeing its own bound `item` variable in the prompt context.
3. Persist each invocation as a distinct `step_runs` row keyed by the existing `batch_index` column (which was added for exactly this purpose in the initial schema).
4. Gather the per-item outputs into an aggregated batch output.
5. When every item completes successfully, advance the run via the step's `on_success` target as if the batch were a single completed step.
6. If any item fails, route through the existing failure cascade (handler → escalation → `on_fail` → `fail`) using the first failure as the canonical error.

After F11 lands, **every definition field the package accepts is active at runtime**. The "what it does not yet do" list in the README drops to only two items: automatic `/start` execution and background queue dispatch for non-parallel steps — both of which are design intent, not gaps.

---

## Cross-cutting principles

These carry over from the remediation plan and hold for F11:

1. **No forced infrastructure.** The default parallel strategy must work on every Laravel queue driver, including `sync` (tests), `database` (SQLite-backed deployments), and Redis/SQS/Beanstalkd. No new dependencies in `composer.json`.
2. **Optimistic concurrency stays sacred.** Per-item jobs write *only* their own `step_runs` row, never the `pipeline_runs` row. The supervisor still owns every `pipeline_runs.revision` bump.
3. **Compiled snapshot is runtime source of truth.** New fields on `StepDefinitionData` that influence execution must be threaded through `WorkflowCompiler::compileStep()` *and* `RunProcessor::buildStepInput()`.
4. **One concurrency boundary per run.** The existing `RunLockProvider::withLock($runId, ...)` serializes all mutating run operations. Fan-out must respect this boundary on dispatch and on the `then`/`catch` callback that reassembles the batch.
5. **Every change flips a tripwire.** The `InertFieldRegressionTest::it does not fan out parallel/foreach steps today (F11 tripwire)` test is the canary. F11 rewrites it into the active assertion.
6. **composer test + composer analyse + pint --test stay green at every commit.**

---

## Decisions needed before implementation

These are the load-bearing design calls. I've listed my recommended answers; please confirm or override before I build anything.

### D1 — Foreach expression syntax

**Options:**
- (A) **Dot-path** against the run context: `foreach: input.urls`, `foreach: output.research.links`, `foreach: context.batch_items`. Uses `data_get()` for resolution, matching the existing `context_map` pattern.
- (B) **Twig expression**: `foreach: '{{ items }}'`. Reuses `TemplateRenderer`, but Twig renders to strings — you'd need custom logic to intercept the rendered result and re-parse it as an array, which is awkward.
- (C) **Literal array inline**: `foreach: [alpha, beta, gamma]`. Breaks once items become dynamic.

**Recommendation: (A) dot-path.** Matches `context_map`, zero new syntax to learn, cleanly expressible in YAML, works with any array the preceding step emitted. Reject `foreach: '{{ ... }}'` syntax at validator level to avoid confusion with prompt templates.

### D2 — Per-item binding key

When rendering the prompt template for each item, which variable name holds the current item?

**Recommendation: `item`** as a hard-coded key in the prompt context, alongside the existing `input`, `context`, `output`, `workflow`, `step` keys. Optional future extension: `foreach_key: customName` on the step if someone needs a different name, but not in v1.

Index is also exposed as `batch_index` in the context so templates can do `{{ batch_index }} of {{ ... }}` if they want.

### D3 — Batch result shape

When all items complete, the parent step's output is aggregated from the N per-item outputs. What shape?

**Options:**
- (A) **Array of outputs**: `[item0_output, item1_output, ...]`. Simplest. Downstream steps access via `output.previous_step[0].headline`.
- (B) **Map keyed by index**: `{0: item0_output, 1: item1_output}`. Same information, slightly clunkier in YAML.
- (C) **User-defined reducer**: a new `reduce:` field on the step that specifies a template or agent to combine results. Overkill for v1.

**Recommendation: (A) array of outputs**, preserving foreach input order. Users who need keyed access can add a separate step with a `context_map` that reshapes the array.

The aggregated parent step output stored on the run becomes:
```json
{
  "batch": [
    {"headline": "First summary", ...},
    {"headline": "Second summary", ...}
  ],
  "batch_size": 2,
  "batch_failures": 0
}
```

### D4 — Failure semantics: fail-fast vs allow-failures

When one item fails, does the batch fail immediately, or does it continue and report partial results at the end?

**Options:**
- (A) **Fail-fast** (Laravel `Bus::batch` default): first failure cancels in-flight items, the `catch` callback fires, and the parent step is marked failed. Existing failure cascade (handler → escalation → on_fail) runs on the parent.
- (B) **Allow failures**: every item runs to completion; the parent step is marked completed with `batch_failures > 0` in its output; users handle partial failures via post-processing.

**Recommendation: ship (A) as the default in v1.** Add a `parallel_allow_failures: true` step field as a v1.1 extension if someone asks for it. Fail-fast matches the existing supervisor semantics (one failed step → whole run fails unless handled).

### D5 — Retry budget semantics

The existing `retries` field applies per step. For a parallel step, does the retry budget apply:

- (A) **Per item independently**: each item may retry up to `retries` times on its own
- (B) **Per batch as a whole**: the whole batch retries up to `retries` times, re-running every item including the ones that succeeded on the previous attempt
- (C) **Per item, but batch succeeds only if every item succeeds after its own retry budget**

**Recommendation: (A) per item**. Rationale: a retry is a per-step-invocation concept, and conceptually each foreach item is its own step invocation. Jobs that fail get retried by the queue worker (up to `retries` times) before they flag the batch as failed. This is also how Laravel's own `Bus::batch` works — each job has its own retry budget via `$tries`.

### D6 — Max batch size

Should there be a hard cap on `count(foreach_items)`?

**Recommendation: no hard cap in v1**, but add a config key `conductor.parallel.max_batch_size` (default `1000`) that throws at dispatch time if exceeded. Dispatching 100,000 jobs from a single workflow step is a footgun worth protecting users from, but a cap low enough to be restrictive (say, 100) is too restrictive for legitimate use.

### D7 — Nested parallelism

Can a parallel step's item itself be a parallel step?

**Recommendation: not in v1.** If a parent step has `parallel: true`, any step reachable via `on_success`/`on_fail` may also be `parallel: true` — that's fine (they run sequentially at the top level). But *during* a batch, we don't dispatch sub-batches from inside a running job. The validator throws if any step declares `parallel: true` AND targets another `parallel: true` step via `foreach`. (In practice this is hard to validate statically because the `foreach` expression is dynamic, so the check is "don't allow recursive dispatch at runtime" — the batch item job checks the run lock and refuses to dispatch further batches within the same lock window.)

### D8 — Queue driver semantics

When `queue.default` is `sync`, the jobs run inline and the `then` callback fires before `dispatch()` returns — the HTTP response from `/continue` includes the final decision. When it's `database`/`redis`/etc., the jobs run out of band and `/continue` returns an intermediate state.

**Recommendation: make the HTTP response reflect reality**:

- On `/continue` when the supervisor decides to dispatch a batch, return a `SupervisorDecisionData` with new action `batching` and a `next_poll_after: <iso8601>` field suggesting when the caller should poll `/runs/{id}`.
- The actual advance happens inside the `then`/`catch` callback, which acquires the run lock, re-enters the supervisor, and persists the new state.
- The callback also fires a new `BatchCompleted` or `BatchFailed` event so hosts can push notifications / webhooks without polling.

Under `sync`, the `batching` decision is immediately followed by the supervisor's advance decision (both happen inside `dispatch()`). The HTTP response could return EITHER the `batching` decision OR the final advance decision. For consistency, return the LAST decision — which under `sync` is the advance, and under async is the `batching`. This matches "the decision the caller can act on right now."

---

## Architecture

### Layer diagram

```
HTTP POST /continue
        │
        ▼
  Conductor::continueRun  (acquires RunLockProvider::withLock)
        │
        ▼
  RunProcessor::continueRun  (inside lock)
        │
        ▼
  Supervisor::evaluate
        │
        ├─── step is not parallel ──▶  existing path (execute, advance, fail, etc.)
        │
        └─── step is parallel + foreach pending ──▶  Supervisor::dispatchBatch
                                                      │
                                                      ▼
                                    ParallelExecutionStrategy::fanOut
                                                      │
                                                      ▼
                                    JobBatchParallelStrategy  (default)
                                                      │
                                                      ▼
                                    Bus::batch([ExecuteBatchItemJob, ...])
                                        ->then(BatchCompletionHandler)
                                        ->catch(BatchFailureHandler)
                                        ->dispatch()
                                                      │
                        ┌─────────────────────────────┼─────────────────────────────┐
                        ▼                             ▼                             ▼
              ExecuteBatchItemJob #0         ExecuteBatchItemJob #1         ... #N-1
                        │                             │                             │
                        ▼                             ▼                             ▼
              [item-specific step input]    [item-specific step input]    [...]
              executor->execute             executor->execute              executor->execute
              store->updateStepExecution    store->updateStepExecution     store->updateStepExecution
              (batch_index=0)               (batch_index=1)                (batch_index=N-1)

           When all N jobs complete:
                        │
                        ▼
              BatchCompletionHandler (closure on the batch)
                        │
                        ▼
              $lockProvider->withLock → load run → aggregate outputs → persist completed →
              Supervisor::evaluate (now sees parent step as completed) → advance via on_success

           If any job throws:
                        │
                        ▼
              BatchFailureHandler (closure on the batch)
                        │
                        ▼
              $lockProvider->withLock → mark parent step failed → run failure cascade
              (handler → escalation → on_fail → fail)
```

### New components

```
src/Execution/
    ParallelExecutionStrategy.php          ← new contract
    JobBatchParallelStrategy.php           ← new default implementation
    Jobs/
        ExecuteBatchItemJob.php            ← new queueable job, one per batch item
        BatchCompletionHandler.php         ← invokable closure bound to Bus::batch->then()
        BatchFailureHandler.php            ← invokable closure bound to Bus::batch->catch()

src/Engine/
    ForeachResolver.php                    ← evaluates a `foreach: dot.path` against run context
    BatchAggregator.php                    ← gathers per-item outputs into the parent step's batch output

src/Data/
    BatchItemStateData.php                 ← optional new DTO; may be folded into StepExecutionStateData

src/Events/
    BatchDispatched.php                    ← fired when batch is queued
    BatchItemCompleted.php                 ← fired by each job on success
    BatchItemFailed.php                    ← fired by each job on failure
    BatchCompleted.php                     ← fired by BatchCompletionHandler
    BatchFailed.php                        ← fired by BatchFailureHandler

src/Contracts/
    ParallelExecutionStrategy.php          ← contract interface
```

### Modified components

```
src/Engine/Supervisor.php                  ← add dispatchBatch, detect parallel-pending, aggregate on complete
src/Engine/RunProcessor.php                ← skip single-execution path when step is parallel; delegate to supervisor
src/Contracts/WorkflowStateStore.php       ← add updateStepExecution(runId, attempt, batchIndex, state)
src/Persistence/DatabaseWorkflowStateStore.php  ← implement updateStepExecution
src/Persistence/OptimisticRunMutator.php   ← add updateStepExecution that writes a single step_runs row only
src/Data/SupervisorDecisionData.php        ← add 'batching' to allowed actions
src/LaravelConductorServiceProvider.php    ← bind ParallelExecutionStrategy
config/conductor.php                       ← add conductor.parallel block (strategy, max_batch_size, queue)
```

---

## Design details

### D1 — Foreach resolution (`src/Engine/ForeachResolver.php`)

```php
final class ForeachResolver
{
    /**
     * @return array<int, mixed>
     */
    public function resolve(string $expression, WorkflowRunStateData $run, StepDefinitionData $step): array
    {
        $expression = trim($expression);

        if ($expression === '') {
            throw new InvalidForeachExpressionException($step->id, 'empty expression');
        }

        // Reject Twig-looking expressions loudly — they would silently return
        // a string from TemplateRenderer and then fail in a confusing way.
        if (str_contains($expression, '{{') || str_contains($expression, '}}')) {
            throw new InvalidForeachExpressionException(
                $step->id,
                'Twig expressions are not supported in foreach. Use a dot-path like "input.urls".'
            );
        }

        $context = [
            'input'    => $run->input,
            'context'  => $run->context,
            'output'   => $run->output,
            'workflow' => $run->workflow,
            'step'     => $step->toArray(),
        ];

        $value = data_get($context, $expression);

        if (! is_array($value)) {
            throw new InvalidForeachExpressionException(
                $step->id,
                sprintf('expression [%s] did not resolve to an array (got %s).', $expression, get_debug_type($value))
            );
        }

        if ($value === []) {
            throw new EmptyForeachException($step->id, $expression);
        }

        return array_values($value);
    }
}
```

**Design notes:**
- `array_values()` normalizes associative arrays to integer-indexed ones. `batch_index` is always `0..N-1`.
- Empty arrays throw `EmptyForeachException` which the supervisor catches and handles specially — "a parallel step with zero items is a noop, advance immediately via `on_success`" is one option, "fail with empty batch error" is another. **Decision: advance via on_success** since that matches the "nothing to do" semantic better than failing.

### D2 — Item binding (`RunProcessor::buildStepInput` gains a variant)

A new method `buildBatchItemStepInput($run, $step, $stepDefinition, $item, $batchIndex)` that extends the existing `buildStepInput` by:

- Adding `item` to the prompt context (so `{{ item.url }}` works in templates)
- Adding `batch_index` (int) and `batch_total` (int) to the prompt context
- Adding the same values to the meta array so the executor and tools can see them if they want

### D3 — Per-item persistence (`WorkflowStateStore::updateStepExecution`)

New contract method:

```php
public function updateStepExecution(
    string $runId,
    int $attempt,
    int $batchIndex,
    StepExecutionStateData $state,
): void;
```

The database implementation writes a single `step_runs` row using `updateOrCreate` with the composite unique key `(pipeline_run_id, step_definition_id, attempt, batch_index)`. It does NOT touch `pipeline_runs` — no revision bump, no lock conflict with other concurrent batch jobs.

This is the one new persistence path that deliberately bypasses optimistic concurrency. Safe because:

- The composite unique key ensures each batch item owns its own row (different `batch_index`).
- The parent `pipeline_runs` row is untouched until the `then`/`catch` callback runs, at which point it's a single-writer operation under the run lock.

### D4 — The job (`ExecuteBatchItemJob`)

```php
final class ExecuteBatchItemJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $runId,
        public readonly string $stepDefinitionId,
        public readonly int $attempt,
        public readonly int $batchIndex,
        /** @var mixed */
        public readonly mixed $item,
    ) {
    }

    public function handle(
        WorkflowStateStore $store,
        WorkflowStepExecutor $executor,
        RunProcessor $processor,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = $store->get($this->runId);
        if ($run === null) {
            throw new RunNotFoundException($this->runId);
        }

        $stepDefinition = $this->resolveStep($run, $this->stepDefinitionId);
        $input = $processor->buildBatchItemStepInput(
            run: $run,
            stepDefinition: $stepDefinition,
            item: $this->item,
            batchIndex: $this->batchIndex,
            batchTotal: count((array) $this->batch()?->totalJobs),
        );

        // Write the "running" marker for this item only
        $store->updateStepExecution(
            runId: $this->runId,
            attempt: $this->attempt,
            batchIndex: $this->batchIndex,
            state: $this->runningState($input),
        );

        try {
            $output = $executor->execute($stepDefinition->agent, $input);
        } catch (Throwable $exception) {
            $store->updateStepExecution(
                runId: $this->runId,
                attempt: $this->attempt,
                batchIndex: $this->batchIndex,
                state: $this->failedState($input, $exception->getMessage()),
            );

            event(new BatchItemFailed($this->runId, $this->stepDefinitionId, $this->batchIndex, $exception));

            throw $exception; // Bus::batch needs the throw to count as a failure
        }

        $store->updateStepExecution(
            runId: $this->runId,
            attempt: $this->attempt,
            batchIndex: $this->batchIndex,
            state: $this->completedState($input, $output),
        );

        event(new BatchItemCompleted($this->runId, $this->stepDefinitionId, $this->batchIndex, $output));
    }

    public function tries(): int
    {
        // Per-item retry budget (see D5). The actual budget comes from the
        // step definition, but the step is not deserialized here; the job
        // retries are capped by a sensible default, and the step's retry
        // budget is enforced at the failure-handling layer when the batch
        // rolls up.
        return 3;
    }
}
```

**Concerns:**

- The job carries the `$item` payload through Laravel's queue serialization. This means items must be JSON-serializable (no closures, no resources). For complex items (Eloquent models, etc.) we rely on `SerializesModels` to restore them. **Decision: require items be plain arrays or scalars in v1.** Document this in the scaffold and the docs. If a user hits this, they can reshape their data in a preceding step.
- The job does NOT instantiate `StepInputData` itself — it delegates to `RunProcessor::buildBatchItemStepInput()` so all the prompt-template rendering lives in one place.
- `$this->batch()?->cancelled()` is a first check so fail-fast semantics work: once one job throws, the batch is cancelled and subsequent jobs exit without running.

### D5 — The strategy (`JobBatchParallelStrategy`)

```php
final class JobBatchParallelStrategy implements ParallelExecutionStrategy
{
    public function __construct(
        private readonly BatchCompletionHandler $completionHandler,
        private readonly BatchFailureHandler $failureHandler,
    ) {
    }

    public function fanOut(
        WorkflowRunStateData $run,
        StepDefinitionData $stepDefinition,
        int $attempt,
        array $items,
    ): string {
        $maxBatch = (int) config('conductor.parallel.max_batch_size', 1000);
        if (count($items) > $maxBatch) {
            throw new BatchSizeExceededException($stepDefinition->id, count($items), $maxBatch);
        }

        $jobs = [];
        foreach ($items as $index => $item) {
            $jobs[] = new ExecuteBatchItemJob(
                runId: $run->id,
                stepDefinitionId: $stepDefinition->id,
                attempt: $attempt,
                batchIndex: $index,
                item: $item,
            );
        }

        $queue = (string) config('conductor.parallel.queue', 'default');

        $pendingBatch = Bus::batch($jobs)
            ->name(sprintf('conductor:run:%s:step:%s:attempt:%d', $run->id, $stepDefinition->id, $attempt))
            ->onQueue($queue)
            ->then(fn (Batch $batch) => ($this->completionHandler)($batch, $run->id, $stepDefinition->id, $attempt))
            ->catch(fn (Batch $batch, Throwable $e) => ($this->failureHandler)($batch, $run->id, $stepDefinition->id, $attempt, $e));

        $dispatched = $pendingBatch->dispatch();

        event(new BatchDispatched($run->id, $stepDefinition->id, $attempt, count($items), $dispatched->id));

        return $dispatched->id;
    }
}
```

**Why invokable handler classes instead of inline closures?**
Bus::batch closures are serialized and restored when the callback fires. Inline closures referencing `$this` fail serialization. Invokable handlers are container-resolved — the batch persists only the class name, and Laravel resolves it fresh when the callback fires. This is the same pattern Laravel's own docs recommend for batch callbacks with non-trivial logic.

### D6 — Completion handler (`BatchCompletionHandler`)

```php
final class BatchCompletionHandler
{
    public function __construct(
        private readonly RunLockProvider $lockProvider,
        private readonly WorkflowStateStore $stateStore,
        private readonly Supervisor $supervisor,
        private readonly BatchAggregator $aggregator,
    ) {
    }

    public function __invoke(Batch $batch, string $runId, string $stepDefinitionId, int $attempt): void
    {
        $this->lockProvider->withLock($runId, function () use ($batch, $runId, $stepDefinitionId, $attempt): void {
            $run = $this->stateStore->get($runId);
            if ($run === null) {
                return; // run was deleted mid-batch — nothing to do
            }

            $batchRows = $this->collectBatchRows($run, $stepDefinitionId, $attempt);
            $aggregated = $this->aggregator->aggregate($batchRows);

            // Persist the parent step as completed with the aggregated output
            $run = $this->markParentCompleted($run, $stepDefinitionId, $attempt, $aggregated);

            // Re-enter the supervisor so it sees the now-completed parent step
            // and runs the advance path
            $this->supervisor->evaluate($run->id, $stepDefinitionId);

            event(new BatchCompleted($runId, $stepDefinitionId, $attempt, count($batchRows)));
        });
    }
}
```

### D7 — Failure handler (`BatchFailureHandler`)

```php
final class BatchFailureHandler
{
    public function __invoke(Batch $batch, string $runId, string $stepDefinitionId, int $attempt, Throwable $firstFailure): void
    {
        $this->lockProvider->withLock($runId, function () use ($runId, $stepDefinitionId, $attempt, $firstFailure): void {
            $run = $this->stateStore->get($runId);
            if ($run === null) {
                return;
            }

            // Mark the parent step as failed with the first item's error. The
            // existing failure cascade in Supervisor::handleFailure handles
            // handler matching, escalation, on_fail routing, and optimistic
            // concurrency on the parent.
            $run = $this->markParentFailed($run, $stepDefinitionId, $attempt, $firstFailure);
            $this->supervisor->evaluate($run->id, $stepDefinitionId);

            event(new BatchFailed($runId, $stepDefinitionId, $attempt, $firstFailure->getMessage()));
        });
    }
}
```

---

## Supervisor changes

### New branch in `evaluate()`

At the top of `Supervisor::evaluate()`, after the existing terminal/waiting checks:

```php
if ($step->status === 'pending' && $stepDefinition->parallel && $stepDefinition->foreach !== null) {
    return $this->dispatchBatch($run, $step, $stepDefinition);
}
```

### New `dispatchBatch()` method

```php
private function dispatchBatch(
    WorkflowRunStateData $run,
    StepExecutionStateData $step,
    StepDefinitionData $stepDefinition,
): SupervisorDecisionData {
    try {
        $items = $this->foreachResolver->resolve($stepDefinition->foreach, $run, $stepDefinition);
    } catch (EmptyForeachException) {
        // Empty batch → nothing to do → advance via on_success
        return $this->advance($run, $step, $stepDefinition, decisionReason: 'foreach resolved to an empty array');
    } catch (InvalidForeachExpressionException $e) {
        return $this->fail($run, $stepDefinition->id, $e->getMessage());
    }

    // Persist "batching" state — parent step status=running, child rows pending
    $run = $this->persistBatchDispatched($run, $step, $stepDefinition, $items);

    // Dispatch the batch
    $batchId = $this->parallelStrategy->fanOut($run, $stepDefinition, $step->attempt, $items);

    return new SupervisorDecisionData(
        action: 'batching',
        reason: sprintf('Dispatched %d batch items (batch id %s).', count($items), $batchId),
    );
}
```

### Aggregation branch in `evaluate()`

When the completion handler re-invokes `$this->supervisor->evaluate()`, the supervisor sees the parent step with status `completed` and runs the normal advance logic — no new code needed beyond aggregating the child rows into the parent's output, which the `BatchCompletionHandler` does before calling evaluate.

---

## Validator changes

### New rules (`WorkflowDefinitionValidator::validateStep`)

1. If `parallel: true`, `foreach` must be a non-empty dot-path (already exists, but tighten: reject `{{}}` syntax).
2. If `parallel: true`, `foreach` must NOT contain Twig markers (reject at validation, not at runtime).
3. If `parallel: true` and `retries` is absent, default to `0` — retries on batches get confusing fast (see D5).
4. (From D6) **No validator rule for max batch size** — that's a runtime concern because `foreach` is dynamic.

### No new fields yet

`parallel_allow_failures`, `parallel_max_concurrency`, `parallel_timeout` are all potential v1.1 extensions. Not in F11.

---

## Tests

### Foreach resolver unit tests (`tests/Feature/ForeachResolverTest.php`)

- Resolves `input.items` to an array
- Resolves `output.previous.urls` to an array
- Resolves nested dot-paths like `context.batches.0.items`
- Throws on empty expression
- Throws on `{{ twig }}` expression
- Throws when the resolved value is not an array (string, int, null, object)
- Throws `EmptyForeachException` on an empty array
- Normalizes associative arrays to integer-indexed via `array_values`

### Job unit tests (`tests/Feature/ExecuteBatchItemJobTest.php`)

- Runs the executor with item-bound prompt context
- Persists `running` → `completed` via `updateStepExecution`
- Persists `running` → `failed` on exception and re-throws
- Fires `BatchItemCompleted` / `BatchItemFailed` events
- Short-circuits when `batch()->cancelled()` returns true

### Strategy unit test (`tests/Feature/JobBatchParallelStrategyTest.php`)

- Dispatches one job per item via `Bus::fake()`
- Rejects batches exceeding `conductor.parallel.max_batch_size`
- Invokable handlers registered via `then` / `catch` — assert via `Bus::assertBatched(fn (PendingBatch $b) => ...)`

### Integration test (`tests/Feature/ParallelForeachEndToEndTest.php`)

Five scenarios under the `sync` queue driver (so the batch runs inline and `/continue` returns the final decision):

1. **Happy path** — 3-item batch, all succeed, parent step completes, run advances via `on_success`, aggregated output is an array of 3 outputs in order
2. **Empty foreach** — `input.items = []`, parent step advances via `on_success` without dispatching
3. **Fail-fast with no handler** — 3 items, item 1 throws, batch cancels, parent step fails, failure handler (none) → escalation (no budget) → `on_fail: cleanup` → cleanup step runs
4. **Fail-fast with a matching handler** — same as above but `failure_handlers: [{match: ..., action: retry}]`, whole batch retries with attempt+1
5. **Foreach over a previous step's output** — step A runs, produces `{urls: [...]}`, step B is parallel with `foreach: output.urls`, runs one Atlas call per URL, aggregated results feed step C

### Tripwire flip

`tests/Feature/InertFieldRegressionTest.php::it does not fan out parallel/foreach steps today (F11 tripwire)` gets removed and replaced with the integration test suite above. The file ends up empty except for its docblock — at that point it's deleted entirely (it will be: parallel/foreach was the last tripwire).

### Stress test (optional)

A test that dispatches 100 items and asserts the batch completes. Guards against quadratic complexity in the aggregation path.

---

## Documentation

### README

- Remove "parallel/foreach fan-out" from "What It Does Not Yet Do". The list drops to:
  - does not auto-dispatch the first step when you call `start`
  - does not currently queue step execution in the background (for non-parallel steps)
- Add a new "Parallel Execution" section after "Tools":

  ```markdown
  ## Parallel Execution

  A step may declare `parallel: true` and a `foreach` dot-path to fan out
  execution across every item in the referenced array. Each item runs as
  a separate queue job; results are gathered into an array on the parent
  step's output when every item completes.

  ```yaml
  steps:
    - id: summarize-each
      agent: summarizer
      prompt_template: prompts/summarize.md.j2
      parallel: true
      foreach: input.urls
      on_success: collate
  ```

  Inside the prompt template, each item is bound as `item`:

  ```twig
  Summarize the content at {{ item }} (item {{ batch_index + 1 }} of {{ batch_total }}).
  ```

  Dispatch uses Laravel's `Bus::batch`, so the backend follows your
  `queue.default` config — no new infrastructure required. Under `sync`
  the batch runs inline; under `database`/`redis`/`sqs`/`beanstalkd` it
  runs asynchronously and the `/continue` response returns a `batching`
  decision. Poll `/runs/{id}` or subscribe to the `BatchCompleted`/
  `BatchFailed` events to observe completion.

  Aggregated output shape:

  ```json
  {
    "batch": [ /* output of item 0 */, /* output of item 1 */, ... ],
    "batch_size": N,
    "batch_failures": 0
  }
  ```
  ```

### CLAUDE.md

- Remove `parallel` and `foreach` from the "not yet executed by runtime" bullet.
- Add a new bullet:
  > **Parallel/foreach fan-out is active.** When a step declares `parallel: true` and a `foreach` dot-path, `Supervisor::dispatchBatch()` resolves the foreach against the run context, persists N `step_runs` rows keyed by `batch_index`, and hands off to the bound `ParallelExecutionStrategy` (default: `JobBatchParallelStrategy`, which wraps `Bus::batch`). Each item runs as an `ExecuteBatchItemJob` that writes only its own step row via `WorkflowStateStore::updateStepExecution()` — **this is the one persistence path that deliberately bypasses the optimistic-concurrency revision bump on `pipeline_runs`**. The parent step is advanced by the `BatchCompletionHandler` running inside the run lock, which reassembles the per-item outputs into the parent's aggregated output and re-enters the supervisor. Fail-fast semantics: first item exception cancels the batch via `Bus::batch->cancelled()`, the `BatchFailureHandler` marks the parent failed, and the existing handler→escalation→on_fail cascade runs on the parent step.

### docs/package/WORKFLOW_SETS.md

- Update the Step Fields table rows for `parallel` and `foreach` to describe the active semantics.
- Add a new "Parallel Execution" section mirroring the README.
- Move `parallel` and `foreach` from the "accepted but not fully active" list into the "recommended authoring pattern" list.

### docs/package/API.md

- Add `batching` to the supervisor decision actions list.
- Document the new `BatchDispatched`, `BatchItemCompleted`, `BatchItemFailed`, `BatchCompleted`, `BatchFailed` events.
- Add a section on polling patterns for async queue drivers.

### CHANGELOG.md

- Unreleased entry describing F11's full surface.

---

## Configuration

New config block in `config/conductor.php`:

```php
'parallel' => [
    /*
    |--------------------------------------------------------------------------
    | Parallel Execution Strategy
    |--------------------------------------------------------------------------
    |
    | Controls how steps with `parallel: true` and `foreach: ...` are
    | dispatched. The default strategy uses Laravel's Bus::batch so fan-out
    | follows the host's existing queue configuration — no new infrastructure
    | is required beyond whatever queue driver you already use.
    |
    | Strategies:
    |   - "job_batch" (default): Laravel Bus::batch, one job per item
    |
    */
    'strategy' => Env::get('CONDUCTOR_PARALLEL_STRATEGY', 'job_batch'),

    /*
    | Queue connection and queue name for dispatched batch jobs. Defaults
    | to the framework queue.default.
    */
    'queue' => Env::get('CONDUCTOR_PARALLEL_QUEUE', 'default'),

    /*
    | Maximum number of items a single foreach batch may dispatch. Runtime
    | throws BatchSizeExceededException if the resolved foreach array is
    | larger than this. Protects hosts from accidentally dispatching tens
    | of thousands of jobs from one workflow step.
    */
    'max_batch_size' => (int) Env::get('CONDUCTOR_PARALLEL_MAX_BATCH_SIZE', 1000),
],
```

---

## File-by-file work plan

Breaking F11 into reviewable sub-commits. Each row should be an atomic commit.

| # | Commit | Files | Lines (est) |
|---|---|---|---|
| 1 | **Foreach resolver + exceptions** | `src/Engine/ForeachResolver.php`, `src/Exceptions/InvalidForeachExpressionException.php`, `src/Exceptions/EmptyForeachException.php`, `src/Exceptions/BatchSizeExceededException.php`, `tests/Feature/ForeachResolverTest.php` | ~300 |
| 2 | **Parallel strategy contract + config** | `src/Contracts/ParallelExecutionStrategy.php`, `config/conductor.php` (add parallel block), `src/LaravelConductorServiceProvider.php` (binding) | ~60 |
| 3 | **Batch item job + state store update method** | `src/Execution/Jobs/ExecuteBatchItemJob.php`, `src/Contracts/WorkflowStateStore.php` (add updateStepExecution), `src/Persistence/DatabaseWorkflowStateStore.php`, `src/Persistence/OptimisticRunMutator.php`, `tests/Feature/ExecuteBatchItemJobTest.php` | ~500 |
| 4 | **Batch events** | `src/Events/BatchDispatched.php`, `BatchItemCompleted.php`, `BatchItemFailed.php`, `BatchCompleted.php`, `BatchFailed.php` | ~150 |
| 5 | **Completion + failure handlers** | `src/Execution/BatchCompletionHandler.php`, `src/Execution/BatchFailureHandler.php`, `src/Engine/BatchAggregator.php`, `tests/Feature/BatchHandlersTest.php` | ~400 |
| 6 | **JobBatchParallelStrategy** | `src/Execution/JobBatchParallelStrategy.php`, `tests/Feature/JobBatchParallelStrategyTest.php` | ~250 |
| 7 | **Supervisor dispatch branch + batch-item prompt binding** | `src/Engine/Supervisor.php`, `src/Engine/RunProcessor.php` (new `buildBatchItemStepInput`), `src/Data/SupervisorDecisionData.php` (action enum update if any) | ~300 |
| 8 | **Validator tightening** | `src/Definitions/WorkflowDefinitionValidator.php`, `tests/Feature/WorkflowDefinitionValidationTest.php` (additions) | ~80 |
| 9 | **End-to-end integration tests + tripwire retirement** | `tests/Feature/ParallelForeachEndToEndTest.php`, delete `tests/Feature/InertFieldRegressionTest.php` (empty now) | ~600 |
| 10 | **Documentation** | `README.md`, `CLAUDE.md`, `docs/package/WORKFLOW_SETS.md`, `docs/package/API.md`, `CHANGELOG.md` | ~400 |

**Total estimate:** ~3000 lines of new code + tests + docs across 10 commits. Roughly 2-3 focused sessions.

**Dependencies between commits:** 1 → 2 → 3 → 4 → 5 → 6 → 7 (7 depends on 1-6 being available for the dispatch branch). 8, 9, 10 can land in any order after 7.

---

## Risks and open questions

### R1 — `Bus::batch` callback serialization

Laravel serializes the `then`/`catch` closures and restores them when the callback fires. For invokable handler classes, only the class name is serialized — the handler is re-resolved from the container at callback time. This is the pattern we're using.

**Risk:** if the callback class has constructor dependencies that aren't resolvable from the container at callback time (e.g., request-scoped services), the callback throws. **Mitigation:** `BatchCompletionHandler` and `BatchFailureHandler` take only framework-level singletons (`RunLockProvider`, `WorkflowStateStore`, `Supervisor`, `BatchAggregator`) which are always resolvable. Don't inject anything request-scoped.

### R2 — Clock skew between dispatch and callback

For async queue drivers, the `then` callback fires on a worker process minutes (or hours) after dispatch. Between those two times, the run's state could have been mutated by other requests. The callback always re-loads the run inside the lock before aggregating.

**Risk:** the parent step's `attempt` number might have moved (e.g., a manual retry). **Mitigation:** the callback captures `$attempt` as a closure variable at dispatch time, and aggregates only the `step_runs` rows matching that specific `(step_definition_id, attempt)` pair. A concurrent manual retry creates new rows with `attempt+1` which the callback ignores.

### R3 — Partial state on worker crash

If a worker crashes mid-job, the row is left in `running` status. Laravel's retry mechanism will re-run the job, which calls `updateStepExecution` idempotently (the composite unique key ensures the same row is updated, not a new one created). If all retries fail, the job's failed status propagates to `Bus::batch` which fires the `catch` callback.

**Risk:** the re-run might produce a different output than the original partial run (non-deterministic LLMs). **Mitigation:** nothing to do — this is an inherent property of LLM retries, and the package already has this problem for non-parallel steps. Document it.

### R4 — Queue job size limits

Some queue drivers (SQS in particular) have message size limits (256 KB). A batch item job carrying a large `$item` payload could exceed this. **Mitigation:** document the item-size limit in the README; long-term, offer an "item reference" pattern where items are stored in the run's context and the job carries only the index.

### R5 — `batch_index=null` vs `batch_index=0`

Current non-parallel step rows have `batch_index=null`. Parallel item rows have `batch_index=0..N-1`. The unique key `(pipeline_run_id, step_definition_id, attempt, batch_index)` treats null as a distinct value from any integer. This means a parallel step can coexist with a non-parallel row for the same step_definition_id (which shouldn't happen in practice, but the schema allows it).

**Decision:** the parent step row for a parallel step uses `batch_index=null` (conceptually "the batch as a whole"), and the N child rows use `batch_index=0..N-1`. The supervisor's aggregation reads the child rows; the parent row's status tracks the batch as a whole (running → completed/failed).

### R6 — Tests on the `sync` driver

Under `sync`, `Bus::batch->dispatch()` runs all jobs inline and fires the `then` callback before returning. That means the `then` callback tries to acquire the run lock — but the original HTTP request is still inside `RunProcessor::continueRun` which is also inside the run lock. **Reentrant locking on the same run from the same process.**

Laravel's cache lock supports owner-based re-entry for some drivers but not all. For the `array` driver (used in tests), the second `acquire` fails.

**Mitigation options:**
- (A) In `Supervisor::dispatchBatch`, release the lock before calling `parallelStrategy->fanOut`, then re-acquire after. Complex and error-prone.
- (B) Have the completion handler detect "we're already inside the lock" and skip the `withLock` wrapper. Requires passing a reentrancy hint.
- (C) Use a dedicated "batch complete" reentrancy token per batch.
- (D) **Run integration tests with the `database` or `redis` queue driver** where jobs run out-of-band and the callback fires on a separate process (no reentrancy).

**Decision: (B) with a sentinel.** The handler checks `$lockProvider instanceof NullRunLockProvider` (which is what tests use) OR checks a static "already locked" flag on the handler. For production, the async queue drivers never reenter. For `sync` in production, this is an edge case worth documenting — we can ship (B) and let async drivers be the recommended path.

**Actually the cleanest fix:** under `sync`, don't go through `Bus::batch` at all — run the items sequentially inline and skip the callback hop. This means the `JobBatchParallelStrategy` detects `config('queue.default') === 'sync'` and executes inline, advancing the parent step directly.

Let me promote this to a proper design decision:

### R6 resolution — `sync` queue inline execution

When `config('queue.default') === 'sync'`, `JobBatchParallelStrategy::fanOut` executes each item inline by calling `app(ExecuteBatchItemJob::class, [...])->handle(...)` directly (without going through `Bus::dispatch`), then calls the completion handler in-process. No batch, no reentrancy, no clock skew.

This simplifies the test story enormously:
- Integration tests use the `sync` driver → everything runs inline → deterministic
- Production uses `database`/`redis`/etc. → jobs run out-of-band → callback fires on a worker process → no reentrancy

**Implementation sketch:**

```php
public function fanOut(WorkflowRunStateData $run, StepDefinitionData $stepDefinition, int $attempt, array $items): string
{
    if (config('queue.default') === 'sync') {
        return $this->fanOutInline($run, $stepDefinition, $attempt, $items);
    }

    return $this->fanOutViaBatch($run, $stepDefinition, $attempt, $items);
}

private function fanOutInline(...): string
{
    $batchId = 'inline-'.Str::uuid();
    foreach ($items as $index => $item) {
        $job = new ExecuteBatchItemJob($run->id, $stepDefinition->id, $attempt, $index, $item);
        app()->call([$job, 'handle']);
    }
    // Call the completion handler directly (already inside the lock from the caller)
    ($this->completionHandler)(/* synthetic batch */, $run->id, $stepDefinition->id, $attempt);
    return $batchId;
}
```

The completion handler's `withLock` call becomes a no-op under the existing lock (NullRunLockProvider in tests; production sync re-entrancy is a host-specific concern).

---

## Out of scope (explicitly)

- `parallel_allow_failures: true` (v1.1 extension)
- `parallel_max_concurrency: N` (currently delegated to queue worker pool size)
- Nested parallelism (one parallel step's item dispatching another parallel step)
- Partial aggregation / streaming results to the next step
- Per-item custom timeout (inherits from the parent step)
- Heterogeneous agents per item (all items run the same step definition)
- `reduce:` step field for user-defined aggregation (future)
- Strategy implementations beyond `JobBatchParallelStrategy` (Amp, Swoole, etc. — those are satellite packages)

---

## Definition of done

F11 ships when:

1. ✅ 10 commits landed in the order above
2. ✅ `composer test` passes with 190+ tests (175 baseline + ~15 new F11 tests)
3. ✅ `composer analyse` clean at level 5 with empty baseline
4. ✅ `vendor/bin/pint --test` clean
5. ✅ `.github/workflows/pint.yml` passes in CI
6. ✅ `tests/Feature/InertFieldRegressionTest.php` is deleted (all tripwires have been flipped)
7. ✅ README's "What It Does Not Yet Do" list contains at most two items: auto-dispatch on `/start` and background queueing of non-parallel steps
8. ✅ CLAUDE.md's "not yet executed by runtime" bullet is removed entirely
9. ✅ An end-to-end integration test runs a 3-item foreach under the `sync` driver, asserts the parent step's aggregated output is a 3-element array, asserts the downstream step sees the array via `context_map` or direct access
10. ✅ A production-shape test runs the same scenario under the `database` queue driver with an actual queue worker stub, asserts the `BatchCompleted` event fires and the run advances
11. ✅ CHANGELOG entry describes the shipped surface honestly
12. ✅ No new dependencies in `composer.json`

The Phase A→D remediation plan has been retired — every task it tracked is shipped in v1. F11 is the v2 milestone.

---

## Why this is its own milestone and not a remediation task

The Phase A → D remediation pass was about **closing gaps between what the docs advertised and what the code did**. F1–F10 and F12 were all "we said it worked; make it work." Low-surprise, mostly mechanical, with a handful of architectural calls (lock provider, retry backoff).

F11 is **a new feature**. Parallel fan-out has real design questions that deserve a full spec pass (this document), real architectural choices about queue integration and state aggregation, and a meaningful test story that exercises out-of-band callbacks. Bundling it with the remediation pass would have:

- Muddied the remediation PR with a large feature
- Deferred every other P0/P1 fix until F11 was "done"
- Skipped the design discussion we're having in this doc

Shipping F11 as its own thing means the remediation pass is complete on its own terms, F11 is reviewable as a discrete feature, and the test harness / tripwire pattern that the remediation built is already in place to catch regressions.
