# Laravel Conductor — Remediation Plan (Phase A → D)

**Status:** Draft — written 2026-04-07, ready for execution
**Scope:** Production safety, test hardening, cleanup, and small feature gaps
**Out of scope:** F11 (`parallel`/`foreach` fan-out) — split into a separate milestone with its own plan
**Source of findings:** Validation pass on the v0.x package surface (see "Findings provenance" at the bottom)

---

## Goals

Bring the package from "real and working but with peripheral gaps and one production-safety hole" to "shippable for any Laravel host without forced infrastructure, with every advertised field either active or explicitly inert with regression coverage."

Concretely, after Phase D lands:

1. **Concurrent HTTP requests on the same run can no longer race** through `IdempotencyGuard` and trigger duplicate Atlas calls. The package's correctness story is whole.
2. **Retry `delay` is honored** by the package itself, not silently delegated to the caller. No more footguns where a contributor reads the README and assumes backoff exists.
3. **Tools and provider tools work** — workflow YAML can declare `tools: [stock_snapshot]` or `provider_tools: [{type: web_search}]` and the executor invokes them through Atlas. The drop-in example at `/home/shawn/workspace/laravel-projects/conductor-tools/` is the implementation reference.
4. **`defaults` and per-step `timeout` actually take effect.** The compiler merges defaults into each step at compile time; the executor enforces step timeouts as real deadlines.
5. **Per-step `on_fail` is consumed** as a fallback transition when no failure handler matches and escalation is not configured.
6. **Test coverage closes the negative-regression gap.** Every "accepted but inert" field gets a tripwire test that documents current behavior; `IdempotencyGuard` gets direct unit tests.
7. **Code style is clean** — `composer format -- --test` passes; CI gates new PRs against Pint.
8. **Dead Spatie skeleton scaffolding is gone.**

What's explicitly **not** in this plan:

- F11 (`parallel: true` + `foreach` fan-out via `ParallelExecutionStrategy` contract). Deferred to its own milestone because it's a real feature, not a gap-fill, and it needs its own design pass on `JobBatchParallelStrategy` semantics, batch persistence in `step_runs.batch_index`, and integration with the supervisor's advance logic.
- Background queue dispatch for `/start` and `/retry` (the README's "does not currently queue step execution" stays true after this remediation pass).
- New persistence drivers beyond the existing `DatabaseWorkflowStateStore`.

---

## Cross-cutting principles

These hold for every task in this plan. Re-read them whenever you're tempted to deviate.

1. **No forced infrastructure.** Nothing in this plan adds a hard dependency on Redis, Memcached, Beanstalk, or any other backend. Where the package needs a primitive (locking, caching, queues), it consumes whatever the host has already configured through Laravel's standard contracts (`Cache::lock()`, `Bus::dispatch()`, etc.). SQLite-only deployments must keep working.
2. **Optimistic concurrency stays sacred.** Every state write goes through `OptimisticRunMutator` (or `WorkflowStateStore::save($state, $expectedRevision)`) and bumps `revision` by exactly 1. New write paths that violate this fail review.
3. **Compiled snapshot is runtime source of truth.** New step fields that need to influence execution must be threaded through `WorkflowCompiler::compileStep()` *and* `RunProcessor::buildStepInput()`. The runtime never re-reads disk.
4. **`composer test` and `composer analyse` stay green at every commit.** No baseline file growth. No skipped tests.
5. **README, CLAUDE.md, and `docs/package/*` stay in sync with the code.** When a "what it does not yet do" item ships, it moves to "what it does." Any task that flips an inert field active must update *all three* docs in the same commit.
6. **No new dependencies in `composer.json`** without a written justification in this plan.
7. **One task = one commit.** Each F-item below should be a single squashable commit (or a tight series if the task is large). Don't bundle unrelated work.
8. **Run `composer test && composer analyse && composer format -- --test` before every commit.** After F5 lands, the format check is enforced; before F5 it's advisory.

---

## Phase summary

| Phase | Theme | Tasks | Effort | Blocking? |
|---|---|---|---|---|
| **A — Production safety** | Close the lock-race hole and the retry-delay footgun | F1, F2 | Medium | Blocks B (test changes need new contracts) |
| **B — Test hardening** | Tripwires and direct guard tests | F3, F4, F6 | Small | Blocks C only by convention (cleaner diffs) |
| **C — Cleanup** | Lint hygiene + dead-code removal | F5, F7 | Trivial | Blocks D only by convention |
| **D — Small feature gaps** | Land the inert fields that don't need new infrastructure | F12, F8, F9, F10 | Medium | F12 → standalone; F8 → blocks F9; F10 → standalone |

Ship in order. Within Phase D, F12 lands first (smallest, pre-built), then F8 → F9 → F10. Total estimate from a fresh start: roughly two to four working sessions depending on how thorough you want the test additions to be.

---

# Phase A — Production safety

## F1 — Run lock provider via `Cache::lock`

**Severity:** 🔴 P0
**Effort:** S (now M because of the controller-duplication prerequisite — see below)
**Depends on:** nothing
**Blocks:** none directly, but every other task should land *after* F1 because the lock provides the safety net all subsequent tests rely on

### Why

`src/Contracts/RunLockProvider.php` defines `acquire()`/`release()` and is bound in the service provider, but **zero call sites in `src/` invoke it** (validated independently in two passes). Two concurrent `POST /runs/{id}/continue` requests can both load the same run, both pass `IdempotencyGuard::forEvaluation()` (which is in-process and not race-safe), both call `Atlas::agent(...)->message(...)` (burning two LLM rounds), and only the second persist will fail on optimistic-concurrency revision mismatch. The optimistic concurrency guard at `OptimisticRunMutator.php:39-50` catches the *write*, but it does not prevent the duplicated *side effects*. The same race exists for `/resume` (a valid token is valid for both parallel requests) and `/retry`.

### Prerequisite refactor — centralize resume/retry

`WorkflowController::resume` (`src/Http/Controllers/WorkflowController.php:71-136`) and `WorkflowController::retry` (`src/Http/Controllers/WorkflowController.php:138-194`) **completely bypass** `Conductor::resumeRun()` and `Conductor::retryRun()` and reimplement the same logic directly against the state store. This duplication means F1 would otherwise need to install locks on two parallel code paths.

**Fix:** Make the controller delegate to `Conductor`. Catch domain exceptions and translate to HTTP responses.

```php
// WorkflowController::resume — replaces lines 71-136
public function resume(ResumeWorkflowRequest $request, string $runId, Conductor $conductor): JsonResponse
{
    try {
        $run = $conductor->resumeRun(
            $runId,
            $request->string('resume_token')->toString(),
            $request->input('payload', []),
        );
    } catch (RunNotFoundException) {
        return response()->json(['message' => 'Workflow run not found.'], 404);
    } catch (RunNotWaitingException) {
        return response()->json(['message' => 'Run is not waiting.'], 409);
    } catch (InvalidResumeTokenException) {
        return response()->json(['message' => 'Invalid resume token.'], 422);
    } catch (RunLockedException) {
        return response()->json(['message' => 'Run is currently locked by another request.'], 423);
    }

    // The decision was already evaluated inside Conductor::resumeRun → return the latest run + its decision
    $decision = $conductor->lastDecisionFor($runId);  // see API note below

    return response()->json([
        'data' => $run->toArray(),
        'decision' => $decision->toArray(),
    ]);
}
```

Same shape for `retry()`. The `Conductor::resumeRun` and `Conductor::retryRun` methods get hardened to throw typed exceptions instead of generic `RuntimeException`, and the controller maps those to HTTP responses one-to-one with the existing behavior.

> **API note on `lastDecisionFor`:** The current `Conductor::resumeRun` evaluates and discards the decision (`Conductor.php:104`). For the controller to return it without re-evaluating, either: (a) `Conductor::resumeRun` returns a tuple `[run, decision]`, (b) the run state stores the latest decision (it already does — `step.supervisor_decision`), or (c) we add a small `lastDecisionFor(string $runId): ?SupervisorDecisionData` accessor on the `Conductor` class. Option (a) is cleanest and breaks the smallest contract. **Recommendation:** make `resumeRun` return `array{0: WorkflowRunStateData, 1: SupervisorDecisionData}` (or a small new `WorkflowRunResultData` DTO if you prefer named tuples). Same for the new internal `executeContinue` path.

### Contract change

Replace `src/Contracts/RunLockProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Contracts;

use Closure;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunLockedException;

interface RunLockProvider
{
    /**
     * Run the callback while holding an exclusive lock for the given run.
     *
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws RunLockedException if the lock cannot be acquired within $blockSeconds.
     */
    public function withLock(string $runId, Closure $callback, int $blockSeconds = 5): mixed;
}
```

This replaces the existing `acquire(string, int): bool` / `release(string): void` pair, which had no callers — confirmed by the validation pass.

### New files

#### `src/Exceptions/RunLockedException.php`

```php
<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Exceptions;

use RuntimeException;

final class RunLockedException extends RuntimeException
{
    public function __construct(public readonly string $runId, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Workflow run [%s] is locked by another in-flight request.', $runId), 0, $previous);
    }
}
```

(Add sibling exceptions in the same directory while you're there: `RunNotFoundException`, `RunNotWaitingException`, `InvalidResumeTokenException`, `RunNotRetryableException`, `RunNotCancellableException`, `RunRevisionMismatchException`. They replace the bare `RuntimeException` throws in `Conductor.php`. One exception per file. Each takes whatever context is useful for the controller to render a message.)

#### `src/Support/CacheLockRunLockProvider.php`

```php
<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Support;

use Closure;
use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunLockedException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;

final class CacheLockRunLockProvider implements RunLockProvider
{
    public function __construct(
        private readonly CacheFactory $cache,
    ) {
    }

    public function withLock(string $runId, Closure $callback, int $blockSeconds = 5): mixed
    {
        $store  = config('conductor.locks.store');     // null = use cache.default
        $ttl    = (int) config('conductor.locks.ttl', 30);
        $prefix = (string) config('conductor.locks.prefix', 'conductor:run:');

        try {
            return $this->cache
                ->store($store)
                ->lock($prefix.$runId, $ttl)
                ->block($blockSeconds, $callback);
        } catch (LockTimeoutException $e) {
            throw new RunLockedException($runId, $e);
        }
    }
}
```

The existing `src/Support/NullRunLockProvider.php` becomes a one-line escape hatch:

```php
<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Support;

use Closure;
use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;

final class NullRunLockProvider implements RunLockProvider
{
    public function withLock(string $runId, Closure $callback, int $blockSeconds = 5): mixed
    {
        return $callback();
    }
}
```

### Files modified

- `src/Contracts/RunLockProvider.php` — replaced (contract change).
- `src/Support/NullRunLockProvider.php` — rewritten to match new contract.
- `src/Support/CacheLockRunLockProvider.php` — **new file**.
- `src/Exceptions/*.php` — **new directory**, ~6 exception classes.
- `src/LaravelConductorServiceProvider.php`, lines 34-49 — flip the default binding from `NullRunLockProvider` to `CacheLockRunLockProvider`.
- `src/Engine/RunProcessor.php`, the body of `continueRun()` (lines 29-89) — wrap in `$this->lockProvider->withLock($runId, fn () => /* existing body */)`. Inject `RunLockProvider $lockProvider` into the constructor.
- `src/Conductor.php`:
  - `resumeRun()` (lines 50-107) — wrap body in `withLock`. Update return type to include the supervisor decision (or add `lastDecisionFor` — see API note above). Replace `RuntimeException` throws with typed exceptions.
  - `retryRun()` (lines 109-155) — wrap body in `withLock`. Replace `RuntimeException` throws with typed exceptions.
  - `cancelRun()` (lines 157-187) — wrap body in `withLock`. Replace `RuntimeException` throws with typed exceptions.
  - Inject `RunLockProvider $lockProvider` into the constructor.
- `src/Http/Controllers/WorkflowController.php`:
  - `resume()` (lines 71-136) — replace body with `Conductor::resumeRun()` delegation + exception → HTTP mapping.
  - `retry()` (lines 138-194) — replace body with `Conductor::retryRun()` delegation + exception → HTTP mapping.
  - `cancel()` (lines 196-236) — replace body with `Conductor::cancelRun()` delegation + exception → HTTP mapping.
  - `continueRun()` (lines 43-58) — wrap the `processor->continueRun` call site in a `try { } catch (RunLockedException $e) { return response()->json([...], 423); }` block. (The lock itself is inside `RunProcessor::continueRun` after the change, so the controller just translates the exception.)
  - Drop the now-unused `latestStep()` and `replaceLatestStep()` private methods (lines 238-274) — they only existed for the inlined logic that's now centralized in `Conductor`.
- `config/conductor.php` — add the `locks` block:

  ```php
  'locks' => [
      'store'  => env('CONDUCTOR_LOCK_STORE'),                    // null = use cache.default
      'prefix' => env('CONDUCTOR_LOCK_PREFIX', 'conductor:run:'),
      'ttl'    => (int) env('CONDUCTOR_LOCK_TTL', 30),
  ],
  ```

### Tests to add

#### `tests/Feature/RunLockProviderTest.php` (new)

- `it acquires and releases a lock around the callback` — fake the cache, run a callback that records the lock state, assert acquire→callback→release ordering.
- `it returns the callback result` — `withLock(...)` returns whatever the callback returns.
- `it propagates exceptions from the callback while still releasing the lock` — throw inside the callback, assert exception bubbles, assert subsequent `withLock` on the same key succeeds.
- `it throws RunLockedException when the lock cannot be acquired in time` — manually hold the lock from a separate cache call, then call `withLock(...)` with a small `blockSeconds`, assert `RunLockedException`.
- `it uses the configured store, prefix, and ttl` — assert against `Cache::shouldReceive('store')->with('redis')` etc., driven by config.
- `it falls back to the default store when conductor.locks.store is null` — assert no `store(...)` argument override.

#### `tests/Feature/RunProcessorLockingTest.php` (new)

- `it wraps continueRun in a lock` — bind a recording lock provider, call `continueRun`, assert the callback was invoked exactly once and the lock was held during execution.
- `it returns 423 when /continue cannot acquire the lock` — bind a `RunLockProvider` that always throws `RunLockedException`, hit `POST /runs/{id}/continue`, assert HTTP 423 + body message.
- `it does not call the executor when the lock cannot be acquired` — same setup, assert the bound `WorkflowStepExecutor` fake was never invoked.

#### `tests/Feature/ResumeAndRetryLockingTest.php` (new)

- One test per Conductor method (`resumeRun`, `retryRun`, `cancelRun`) showing the lock is acquired and the typed exception is thrown when the lock fails. Six tests total.

#### Existing tests to update

- `tests/Feature/StartWorkflowTest.php`, `ContinueWorkflowTest.php`, `ResumeWorkflowTest.php` — bind the `NullRunLockProvider` (or a recording provider) in the test bootstrap so the tests stay deterministic. Add a one-line `app()->bind(RunLockProvider::class, NullRunLockProvider::class)` to `tests/TestCase.php` so every feature test inherits the no-op behavior.
- `tests/Feature/PackageSurfaceTest.php` — update the binding assertion at line 202 to expect `CacheLockRunLockProvider` instead of `NullRunLockProvider`.

### Documentation updates

- `README.md` — add a "Concurrency safety" subsection under "Lifecycle Model" or near "What It Does Not Yet Do." Explain that the package now serializes per-run via `Cache::lock` and that the lock backend follows the host's `cache.default`.
- `CLAUDE.md` — update the "Things to know before changing things" section: remove the "RunLockProvider is bound but never invoked" note and replace with "All run mutations are wrapped in `RunLockProvider::withLock`. The default provider uses `Illuminate\Contracts\Cache\Factory` and follows the host's cache backend."
- `docs/package/API.md` — document the new `423 Locked` response on `/continue`, `/resume`, `/retry`, `/cancel`. Add it to the "Failure modes" list for each endpoint.
- `config/conductor.php` inline comments — explain the `locks` block and list the env vars.

### Verification

```bash
composer test
composer analyse
```

Both should be green. New test count should grow by ~12 tests.

```bash
# Manual smoke test of the 423 path:
php artisan tinker
> Cache::lock('conductor:run:test-id', 60)->get();  // hold the lock
> // in another terminal, hit POST /api/conductor/runs/test-id/continue → expect 423
```

### Rollback

`git revert` the F1 commit. The new contract breaks any host that already implemented `RunLockProvider` against the old `acquire`/`release` interface — but we verified there are zero such implementations in the wild (the contract was scaffolding, never wired). Document the contract change in `CHANGELOG.md` under "Breaking changes" so future migrators have a paper trail.

---

## F2 — Retry delay enforcement via `retry_after`

**Severity:** 🔴 P0 (correctness footgun)
**Effort:** S
**Depends on:** F1 (lands after, since both touch `Conductor::retryRun` and `RunProcessor::continueRun`)
**Blocks:** none

### Why

`FailureHandlerData::$delay` is parsed, validated (`>= 0`), and surfaces in `SupervisorDecisionData::$delay` returned to the HTTP caller — but the package never sleeps, queues, or schedules anything based on it. A handler that says "retry with 30s delay" will be hot-looped if a caller naively calls `/continue` again immediately. The README's "what it does not yet do" list does *not* call this out, so a contributor reading the README will reasonably assume the package enforces it.

### Decision

Use **option (2): persist `retry_after` on the run state and reject early `/continue` calls.** This is the cheap correctness win. The full queue path (option 3) is deferred to F11's milestone.

### Files modified

- `src/Data/WorkflowRunStateData.php` — add a new nullable property `?string $retry_after = null` (ISO 8601 timestamp). Spatie laravel-data handles serialization via `from()` automatically.
- `database/migrations/create_pipeline_runs_table.php.stub` — add `$table->timestampTz('retry_after')->nullable();` after the `wait` column. Index the column if you want to support a future "find runs ready to retry" query (optional, can defer).
- `tests/TestCase.php` — mirror the new column in `prepareConductorDatabase()` (the test schema is hand-built; this is the source-of-truth-drift footgun documented in CLAUDE.md).
- `src/Persistence/Models/PipelineRun.php` — add `'retry_after' => 'datetime'` to the `$casts` array.
- `src/Persistence/OptimisticRunMutator.php` — add `'retry_after' => $state->retry_after,` to the `pipelinePayload()` method (line 62-81).
- `src/Persistence/DatabaseWorkflowStateStore.php` — add `'retry_after' => $run->retry_after?->toIso8601String(),` to the `hydrate()` method (line 42-72).
- `src/Engine/Supervisor.php` — in the failure handler `retry` action (around line 280-330, search for the retry decision), when the handler has a `delay > 0`, populate `WorkflowRunStateData::$retry_after` to `now('UTC')->addSeconds($delay)->toIso8601String()` on the persisted run. Same for the escalation evaluator's retry response.
- `src/Engine/RunProcessor.php`, `continueRun()` (lines 29-89) — at the top of the method (after loading the run, before the supervisor evaluation), check `$run->retry_after`. If it's in the future, return a `SupervisorDecisionData(action: 'noop', reason: sprintf('Run is in retry backoff until %s.', $run->retry_after))`. The HTTP layer maps `noop`-with-backoff-reason to a 200 response with the existing decision shape — no new HTTP status needed.
- `src/Engine/Supervisor.php` — when transitioning out of a retry decision (i.e., the next time the supervisor *successfully* advances or completes the step), clear `retry_after` to `null` on the persisted run. This prevents stale backoff timestamps from blocking future retries.

### Tests to add

#### `tests/Feature/RetryBackoffTest.php` (new)

- `it stores retry_after when a failure handler specifies a delay` — set up a failed step that matches a handler with `delay: 30`, evaluate the supervisor, assert the run's `retry_after` is roughly 30 seconds in the future.
- `it returns noop on /continue while retry_after is in the future` — set up a run with `retry_after = now + 1 minute`, hit `/continue`, assert the decision is `noop` with a reason containing "retry backoff".
- `it does not call the executor while retry_after is in the future` — same setup, assert the bound executor fake was never invoked.
- `it allows /continue once retry_after has elapsed` — set up a run with `retry_after = now - 1 second`, hit `/continue`, assert normal execution proceeds.
- `it clears retry_after on the next successful advance` — manually run the full retry cycle, assert that after the retried step completes, `retry_after` is null.
- `it respects retry_after on escalation-driven retries too` — set up an escalation that returns `retry`, assert `retry_after` is populated based on the handler's delay (or zero if no delay).

#### Existing tests to update

- `tests/Feature/RetryAndDispositionTest.php` — any test that exercises a failure handler with a `delay` field: update the assertions to also check `retry_after` was set.
- `tests/Feature/EscalationEvaluatorTest.php` — same.

### Documentation updates

- `README.md`:
  - Remove "it does not currently queue step execution in the background" or qualify it: "step execution is synchronous, but failure-handler retry delays are now enforced via a `retry_after` timestamp on the run dossier."
  - Add a small note in the lifecycle section that `/continue` will return `noop` if a retry backoff is active.
- `CLAUDE.md` — update the "Things to know" section: drop the "retry delays are returned but not enforced" warning. Replace with "Failure handler `delay` is honored via `WorkflowRunStateData::$retry_after`. The database column is `retry_after` on `pipeline_runs`. `/continue` returns a `noop` decision while the backoff is active."
- `docs/package/API.md`:
  - Add `retry_after` to the WorkflowRunStateData field table.
  - Document the new `noop` reason format in the SupervisorDecisionData section.
- `docs/package/WORKFLOW_SETS.md` — under "Failure Handler Fields," update the `delay` row from "Returned in retry decisions, and used for wait timeout calculation" to "Returned in retry decisions, used for wait timeout calculation, and persisted as a backoff window on the run."
- `CHANGELOG.md` — add an entry under "Unreleased": "retry handler delays are now enforced via a persisted `retry_after` window on the run dossier; subsequent `/continue` calls return a `noop` decision until the window elapses."

### Verification

```bash
composer test       # ~5 new tests pass
composer analyse    # 0 errors
```

```bash
# Manual smoke test:
# 1. Trigger a failed step with a delay:30 handler
# 2. Inspect: GET /api/conductor/runs/{id}  → expect retry_after to be ~30s in the future
# 3. Immediately POST /api/conductor/runs/{id}/continue → expect 200 with decision.action == "noop"
# 4. Wait 30 seconds, repeat → expect normal execution
```

### Rollback

`git revert`. No persisted data is broken since the column is nullable; existing rows will just have `retry_after = null` and behave as before.

---

# Phase B — Test hardening

These three tasks add tests but no production code. They're tripwires for the upcoming feature work and should land before Phase D so the feature work has something to flip from "asserts inertness" to "asserts active behavior."

## F3 — `IdempotencyGuard` direct unit tests

**Severity:** 🟡 P1
**Effort:** S
**Depends on:** F1 (the lock work changes the call path; test against the post-F1 surface)

### Why

`src/Engine/IdempotencyGuard.php` has two methods (`forEvaluation`, `forRetryAttempt`) with non-trivial branching, and **zero tests exercise them directly.** Coverage is incidental through end-to-end tests. The lock work in F1 leans on this guard for in-process race protection; the guard needs its own tests so future refactors can't silently break that protection.

### Files modified

- `tests/Feature/IdempotencyGuardTest.php` — **new file**.

### Tests to add

`forEvaluation`:

- `it returns noop when the run is in a terminal status` — for each of `completed`, `failed`, `cancelled`, build a run + step, assert the guard returns a decision with `action: 'noop'` and a reason mentioning terminality.
- `it returns noop when current_step_id no longer matches the target step` — set up a run whose `current_step_id` is `step-b`, ask the guard about `step-a`, expect noop.
- `it returns noop when the step execution state cannot be found` — pass a step id that has no entry in `$run->steps`, expect noop.
- `it returns noop when the step already has a supervisor_decision recorded` — set up a step with a non-null `supervisor_decision`, expect noop.
- `it returns null (proceed) when the step is fresh and the guard has nothing to short-circuit` — set up a clean pending step, expect the guard returns `null`.

`forRetryAttempt`:

- `it returns false when the run is terminal` — same matrix as above.
- `it returns false when current_step_id has moved past the target step` — same shape.
- `it returns false when the step status is not pending` — try `running`, `completed`, `failed`, `skipped`, expect false for each.
- `it returns false when the attempt number has changed` — set up a step with `attempt: 2`, ask the guard about `attempt: 1`, expect false.
- `it returns true when the step is pending and on the expected attempt` — happy path.

Total: ~10 small tests. Each is 10-20 lines.

### Documentation updates

None — this is pure test coverage.

### Verification

```bash
composer test --filter=IdempotencyGuardTest
```

All 10 tests green.

### Rollback

`git revert`. Zero production impact.

---

## F4 — Negative-regression tests for inert fields

**Severity:** 🟡 P1
**Effort:** S
**Depends on:** F1 (so the fixtures can use the post-F1 lock binding)
**Blocks:** F8, F9, F10, F12 (these tests get *flipped* to active assertions when each field becomes runtime-active — flipping is easier than authoring from scratch)

### Why

There is no test asserting "a workflow with `parallel: true` does NOT actually fan out." If a future contributor "implements" parallel fan-out with broken semantics, the existing tests will still pass because they only assert the field round-trips through compilation. Same gap for `tools`, `provider_tools`, `on_fail`, and `defaults`. Add tripwires per field that document current behavior and force a deliberate decision when they flip.

### Files added

- `tests/Feature/InertFieldRegressionTest.php` — **new file**.

### Tests to add

For each inert field, write one test asserting current (inert) behavior. When the corresponding F-task lands, the test gets rewritten to assert the *new* (active) behavior. Each test should leave a comment block explaining the linkage:

```php
it('does not fan out parallel/foreach steps today', function () {
    // F11 will flip this to assert actual fan-out via JobBatchParallelStrategy.
    // For now this test pins the inert behavior so silent regressions are caught.

    // ... build a workflow with parallel: true + foreach: items
    // ... start a run, call /continue
    // ... assert exactly ONE step row was created in step_runs (not N)
    // ... assert the executor was called exactly ONCE
});
```

Specific tests:

- `it does not fan out parallel/foreach steps today` (F11 tripwire)
- `it does not invoke tools declared on a step today` (F12 tripwire — assert the executor's `withTools` was NOT called)
- `it does not invoke provider_tools declared on a step today` (F12 tripwire)
- `it does not consume per-step on_fail as a transition target today` (F10 tripwire — set up a failed step with `on_fail: complete`, assert the run does NOT transition to complete and instead falls through to escalation)
- `it does not enforce defaults.timeout today` (F9 tripwire — set up a workflow with `defaults: {timeout: 1}`, run a step that takes 2 seconds, assert it completes normally without being interrupted)
- `it does not merge defaults into individual steps today` (F8 tripwire — set up a workflow with `defaults: {retries: 5}` and a step with no explicit `retries`, compile it, assert the compiled step's `retries` is `0` (the property default), not `5`)

Total: 6 tests. Each one will be rewritten by the F-task that flips its corresponding field to active.

### Documentation updates

- `tests/Feature/InertFieldRegressionTest.php` — top-of-file docblock explaining the tripwire pattern, listing the F-tasks that will rewrite each test.

### Verification

```bash
composer test --filter=InertFieldRegressionTest
```

All 6 tests green.

### Rollback

`git revert`. No production impact.

---

## F6 — Defensive-branch coverage in `Supervisor::evaluate`

**Severity:** 🟢 P2
**Effort:** Trivial (~30 minutes)
**Depends on:** nothing
**Blocks:** nothing

### Why

`src/Engine/Supervisor.php:67-70` (pending step with no `condition` and no `wait_for` → returns `noop` with reason "Pending step has no deterministic supervisor action yet") and `src/Engine/Supervisor.php:87-92` (unknown status → defensive `noop`) are untested. They're defensive branches that guard against contract violations from future code. Add tiny tests so the contract is documented and a future refactor can't silently change the noop reason without updating tests.

### Files modified

- `tests/Feature/SupervisorDefensiveBranchesTest.php` — **new file**.

### Tests to add

- `it returns noop with deterministic-action reason when pending step has neither condition nor wait_for` — set up a clean pending step, evaluate, assert exact noop reason text.
- `it returns noop with unsupported-status reason when step status is unknown` — set up a step with `status: 'definitely-not-a-real-status'` (you'll need to bypass the DTO validation; reach into the step row directly via the model), evaluate, assert exact noop reason text.

### Documentation updates

None.

### Verification

```bash
composer test --filter=SupervisorDefensiveBranchesTest
```

### Rollback

`git revert`. No impact.

---

# Phase C — Cleanup

## F5 — Pint format pass + CI gate

**Severity:** 🟢 P2
**Effort:** Trivial
**Depends on:** F1, F2 (avoid format churn on files those tasks touch)
**Blocks:** nothing — but lands in C so all subsequent commits stay clean

### Why

`vendor/bin/pint --test` reports style violations in roughly 35 files (cosmetic: empty braces on single line, import ordering, unary operator spacing, brace position). Tests and PHPStan are clean. Land the format pass once, then add a CI step so PRs can't reintroduce drift.

### Files modified

- All ~35 files Pint touches. Run `composer format` (no flags) once and commit the result. **Single commit with no logical changes.**
- `.github/workflows/phpstan.yml` — model a new `pint.yml` workflow on this file. Or extend `phpstan.yml` to run Pint as well. Either is fine; matching the existing structure is cleaner. Suggested:

  ```yaml
  name: pint

  on:
    push:
      paths: ['**.php', '.github/workflows/pint.yml']
    pull_request:
      paths: ['**.php', '.github/workflows/pint.yml']

  jobs:
    pint:
      runs-on: ubuntu-latest
      timeout-minutes: 5
      steps:
        - uses: actions/checkout@v6
        - uses: shivammathur/setup-php@v2
          with:
            php-version: '8.3'
            coverage: none
        - run: composer install --prefer-dist --no-interaction
        - run: vendor/bin/pint --test
  ```

### Tests to add

None.

### Documentation updates

- `README.md` — under "Testing", add a one-liner: `composer format` to fix style, `vendor/bin/pint --test` to check.
- `CLAUDE.md` — update the "Commands" section: change `composer format` from advisory to enforced, mention CI gate.

### Verification

```bash
composer format
git diff --stat       # ~35 files touched, all cosmetic
composer test         # still green (sanity check that format didn't break parsing)
vendor/bin/pint --test  # exit 0
```

### Rollback

`git revert` the format commit. Revert the CI workflow file separately if needed.

---

## F7 — Delete `configure.php`

**Severity:** 🟢 P3
**Effort:** Trivial
**Depends on:** nothing
**Blocks:** nothing

### Why

`configure.php` is 15.6KB of post-`composer create-project` interactive setup script left over from the Spatie package skeleton. The package is well past the "fresh clone" stage — `Entrepeneur4lyf\LaravelConductor` is hard-coded everywhere, the placeholder strings the script searches for are long gone. The script will be inert if anyone runs it. It's confusing baggage in a published package.

### Files modified

- `configure.php` — **delete**.
- `composer.json` — verify there's no `scripts` entry that calls `configure.php`. If there is, remove it (the validation pass found none, but double-check).

### Tests to add

None.

### Documentation updates

- `README.md` — search for any reference to `configure.php` (none expected, but search-replace) and remove.
- `CHANGELOG.md` — single line: "removed the inert Spatie skeleton `configure.php` post-install scaffolding."

### Verification

```bash
composer install      # post-autoload-dump should still run cleanly
composer test         # green
```

### Rollback

`git revert`. The file is checked into git history forever; recovery is trivial.

---

# Phase D — Small feature gaps

## F12 — Drop in conductor-tools example (tools + provider_tools)

**Severity:** 🟡 P1
**Effort:** S (the implementation is pre-built; we add glue + tests + docs)
**Depends on:** F4 (so the inert-tool tripwire test exists to flip)
**Blocks:** nothing

### Why

The example at `/home/shawn/workspace/laravel-projects/conductor-tools/` is a clean drop-in for tool resolution. Atlas 3.0 supports `withTools(array<int, Tool|string>)` at `reference/atlas-3.0.0/src/Pending/AgentRequest.php:190` and `withProviderTools(array<int, ProviderTool>)` at `:202`. The base `Tool` class is at `src/Tools/Tool.php:15` and all 7 provider tools the example imports exist in `src/Providers/Tools/`. The existing `RunProcessor::buildStepInput()` (lines 110-117) already pipes `tools` and `provider_tools` through `StepInputData::$meta`, so engine-side changes are zero. F12 is the smallest remaining feature gap.

### Files added

Copy from `/home/shawn/workspace/laravel-projects/conductor-tools/`:

- `src/Tools/ToolResolver.php` — verbatim from `conductor-tools/src/Tools/ToolResolver.php`.
- `src/Tools/ProviderToolResolver.php` — verbatim from `conductor-tools/src/Tools/ProviderToolResolver.php`.

Replace:

- `src/Execution/AtlasStepExecutor.php` — replace with the version at `conductor-tools/src/Execution/AtlasStepExecutor.php`.

### Files modified

- `src/LaravelConductorServiceProvider.php`, `registeringPackage()` — add the bindings from `conductor-tools/service-provider-additions.php`:

  ```php
  $this->app->singleton(ToolResolver::class, fn ($app) => new ToolResolver($app));
  $this->app->singleton(ProviderToolResolver::class);

  // The existing line:
  // $this->app->singleton(WorkflowStepExecutor::class, AtlasStepExecutor::class);
  // becomes:
  $this->app->singleton(
      WorkflowStepExecutor::class,
      fn ($app) => new AtlasStepExecutor(
          toolResolver: $app->make(ToolResolver::class),
          providerToolResolver: $app->make(ProviderToolResolver::class),
      ),
  );
  ```

- `config/conductor.php` — merge in the `tools` block from `conductor-tools/config/conductor-tools.php`:

  ```php
  'tools' => [
      'namespace' => env('CONDUCTOR_TOOLS_NAMESPACE', 'App\\Tools'),
      'map' => [
          // 'tool_name' => \App\Tools\YourTool::class,
      ],
  ],
  ```

### Tests to add

#### `tests/Feature/ToolResolverTest.php` (new)

- `it resolves a tool from the explicit map` — bind `conductor.tools.map = ['stock_snapshot' => StockSnapshotTool::class]`, call `resolve('stock_snapshot')`, expect the FQCN.
- `it resolves a fully-qualified class name passed directly` — call `resolve('App\\Tools\\StockSnapshotTool')`, expect the same FQCN.
- `it resolves a snake_case identifier via convention` — set `conductor.tools.namespace = 'Tests\\Fixtures\\Tools'`, create a `Tests\Fixtures\Tools\StockSnapshotTool` test class extending `Atlasphp\Atlas\Tools\Tool`, call `resolve('stock_snapshot')`, expect the convention-based FQCN.
- `it resolves a convention-based class without the Tool suffix` — same setup but the test class is named `StockSnapshot` (no suffix), assert convention-alt resolution works.
- `it throws when the resolved class does not extend Tool` — create a class that doesn't extend `Tool`, attempt to resolve, expect `RuntimeException` with the validation message.
- `it throws when no resolution strategy succeeds` — call `resolve('nonexistent_tool')` with empty map and a namespace pointing nowhere, expect `RuntimeException` listing the tried namespaces.
- `it allows runtime registration via register()` — call `$resolver->register('foo', FooTool::class)`, then `resolve('foo')`, expect FooTool.
- `it returns true from has() when resolution succeeds` and `false otherwise`.
- `it resolves multiple identifiers via resolveMany()`.

#### `tests/Feature/ProviderToolResolverTest.php` (new)

- `it resolves a string declaration to the matching provider tool class` — `resolve('web_search')` → `WebSearch` instance.
- `it resolves an object declaration with options` — `resolve(['type' => 'web_search', 'max_results' => 5])` → `WebSearch` instance with `maxResults` constructor arg of 5.
- `it normalizes type aliases (snake_case, kebab-case, with spaces)` — `'web-search'`, `'web search'`, `'web_search'` all resolve to `WebSearch`.
- `it accepts FQCN passthrough for custom provider tools` — `resolve('App\\Custom\\MyProviderTool')` works if the class extends `ProviderTool`.
- `it throws on unknown type` — `resolve('not_a_real_tool')` throws with message listing available types.
- `it throws on missing type key in object declaration` — `resolve(['max_results' => 5])` throws.
- `it converts snake_case option keys to camelCase constructor params` — assert via constructor inspection or via a fake provider tool with explicit parameters.
- `it resolves multiple declarations via resolveMany()`.

#### `tests/Feature/AtlasStepExecutorWithToolsTest.php` (new)

Mirror the existing `tests/Feature/StructuredAtlasExecutionTest.php` pattern:

- `it calls withTools when the step declares tools` — set up a step with `tools: ['fake_tool']`, bind a fake `Atlas` request that records calls, call the executor, assert `withTools(['Tests\\Fixtures\\Tools\\FakeTool'])` was called.
- `it calls withProviderTools when the step declares provider_tools` — same pattern.
- `it does not call withTools when the step has no tools` — happy negative case.
- `it does not call withProviderTools when the step has no provider_tools` — same.
- `it calls both withTools and withProviderTools when both are declared`.
- `it propagates a RuntimeException from the resolver when a tool cannot be resolved` — set up a step with `tools: ['nonexistent']`, expect the error to bubble out of `execute()`.

#### Existing tests to flip

- `tests/Feature/InertFieldRegressionTest.php` — flip the two tool-related tests (`it does not invoke tools` and `it does not invoke provider_tools`) into active assertions. They become "it invokes tools when declared" and "it invokes provider_tools when declared." This is the F4 tripwire paying off.

### Documentation updates

- `README.md`:
  - Remove "it does not currently execute `tools` or `provider_tools`" from the "What It Does Not Yet Do" list.
  - Add a brief "Tools" section after the "Example Workflow" showing how to register a tool via the config map and via the convention namespace.
- `CLAUDE.md`:
  - Update the "Things to know" section: drop `tools` and `provider_tools` from the "accepted but not yet runtime-active" list.
  - Add a sentence to the Execution layer description: "tool identifiers are resolved via `ToolResolver` (explicit map → FQCN → convention namespace) and provider tool declarations via `ProviderToolResolver`. Resolution happens at execution time, not compile time, so workflow snapshots stay portable."
- `docs/package/WORKFLOW_SETS.md`:
  - Update the `tools` row in the Step Fields table from "Preserved and forwarded in step metadata, not invoked by executor today" to "Resolved at execution time via `ToolResolver` and passed to Atlas via `withTools()`."
  - Same for `provider_tools`.
  - Add a new "Tools and Provider Tools" subsection explaining the resolver strategies, with YAML examples for both string and object declarations.
  - Move `tools` and `provider_tools` from the "Treat these as accepted but not fully active" list to the "Recommended Authoring Pattern" list.
- `CHANGELOG.md` — add an entry: "added runtime resolution and invocation of `tools` and `provider_tools` declared on workflow steps via Atlas's `withTools()` and `withProviderTools()`. Resolution supports an explicit config map, FQCN passthrough, and convention-based namespace lookup."

### Verification

```bash
composer test                                       # ~22 new tests pass
composer test --filter=ToolResolverTest             # green
composer test --filter=ProviderToolResolverTest     # green
composer test --filter=AtlasStepExecutorWithTools   # green
composer test --filter=InertFieldRegression         # the two flipped tests pass under their new names
composer analyse                                    # 0 errors
```

### Rollback

`git revert`. The conductor-tools example stays at its original location as a reference. No persisted data is affected.

---

## F8 — `defaults` merge at compile time

**Severity:** 🟡 P1
**Effort:** M
**Depends on:** F4 (the inert-defaults tripwire test exists to flip)
**Blocks:** F9 (timeout enforcement uses the merged defaults)

### Why

The `defaults` block at workflow root is parsed and stored in `CompiledWorkflowData::$defaults` but never applied to individual steps. This makes it useless in practice — you can write `defaults: {retries: 3}` at the top of a workflow and every step still defaults to `retries: 0`. The fix is to merge defaults into each step at compile time so the runtime sees fully-resolved step definitions and doesn't have to know defaults exist.

### Design decision

Apply defaults at **compile time**, not runtime. Reason: the compiled snapshot is the runtime source of truth (per cross-cutting principle 3). Doing defaults merge in `WorkflowCompiler::compileStep()` means every consumer of the compiled snapshot — `RunProcessor`, `Supervisor`, `AtlasStepExecutor` — sees a step where the defaults are already baked in. No runtime branching, no "check defaults if step is null." Simpler and faster.

### Merge semantics

- A field on a step takes precedence over a default. Step `retries: 5` + defaults `retries: 3` → step `retries: 5`.
- A field absent on a step inherits from defaults if defaults specify it.
- Fields not in defaults stay at their property defaults from `StepDefinitionData`.
- The merge is **shallow** for top-level keys. Nested arrays (e.g., `meta`) are NOT deep-merged — that's confusing semantics, easier to reason about as either-or.
- Only these fields are merge-eligible (anything that has a sensible workflow-wide default):
  - `retries`
  - `timeout`
  - `meta` (replace, not merge)
  - `tools`
  - `provider_tools`
- Fields that are step-identity (`id`, `agent`, `prompt_template`, `output_schema`) are **not** merge-eligible. Validation should reject `defaults` blocks containing them.

### Files modified

- `src/Definitions/WorkflowDefinitionValidator.php` — add a validation rule that rejects `defaults` blocks containing identity fields. Add a test for this in `WorkflowDefinitionValidationTest.php`.
- `src/Definitions/WorkflowCompiler.php`:
  - In `compileStep()` (lines 78-110), accept the workflow's `defaults` array as a parameter (or as constructor injection on the compiler — the latter is cleaner because the compiler is already a singleton).
  - For each merge-eligible field, apply: `$step->retries ?? $defaults['retries'] ?? 0`.
  - Pass the merged values to the new `StepDefinitionData` constructor call.
- `src/Definitions/WorkflowCompiler.php`, `compile()` (line 24-56) — extract `$defaults = $definition->defaults` and pass it to `compileStep()`.

### Tests to add

#### `tests/Feature/WorkflowDefaultsMergeTest.php` (new)

- `it merges retries from defaults into a step that does not specify retries`
- `it does not override step retries when both are specified`
- `it merges timeout from defaults`
- `it merges tools from defaults` (acts like the step inherited the same tool list)
- `it does not deep-merge meta — step meta replaces defaults meta when both exist`
- `it leaves step fields untouched when defaults block is empty`
- `it leaves step fields untouched when defaults block is missing entirely` (the existing happy-path tests already cover this; one explicit test is fine)

#### `tests/Feature/WorkflowDefinitionValidationTest.php` (extend)

- `it rejects defaults block containing step-identity fields like agent` — assert the validator error.
- `it rejects defaults block containing prompt_template`
- `it rejects defaults block containing output_schema`
- `it rejects defaults block containing id`

#### Existing tests to flip

- `tests/Feature/InertFieldRegressionTest.php` — flip `it does not merge defaults into individual steps today` to `it merges defaults into individual steps at compile time`.

### Documentation updates

- `README.md` — remove `defaults` from any "not yet active" lists.
- `CLAUDE.md` — drop `defaults` from the inert-fields list.
- `docs/package/WORKFLOW_SETS.md`:
  - Update the `defaults` row in the Top-Level Fields table from "Preserved on the compiled snapshot" to "Merged into each step at compile time. See `Defaults` section below."
  - Add a new "Defaults" section explaining merge semantics, the merge-eligible field list, and the rejection rule for identity fields.
- `CHANGELOG.md` — "the workflow root `defaults` block is now merged into individual steps at compile time. Step fields take precedence over defaults; identity fields (id, agent, prompt_template, output_schema) are rejected from defaults blocks."

### Verification

```bash
composer test
composer analyse
composer test --filter=WorkflowDefaultsMergeTest
```

### Rollback

`git revert`. Backwards compatible — workflows without a defaults block are unaffected.

---

## F9 — Per-step `timeout` enforcement

**Severity:** 🟡 P1
**Effort:** M
**Depends on:** F8 (the merged defaults expose `timeout` to every step)
**Blocks:** nothing

### Why

`StepDefinitionData::$timeout` is validated (`> 0`) and preserved through compilation, but never enforced as an actual deadline. After F8 lands, every step has a `timeout` (either explicit or inherited from defaults), and we can wire it through the executor.

### Design decision

PHP doesn't have a clean cross-platform "execute this synchronous block with a deadline" primitive. The realistic options:

1. **`set_time_limit()`** — sets the execution time limit for the whole script. Per-step granularity is impossible. Rejected.
2. **`pcntl_alarm()` + signal handler** — works on POSIX (not Windows), affects the whole process. Risky inside PHP-FPM. Rejected.
3. **Pass the timeout to Atlas via the request meta and let the HTTP client honor it.** Atlas's underlying HTTP client almost certainly supports per-request timeouts. This is the cleanest answer.

We go with option 3. The executor reads `step->timeout` and passes it to Atlas as a meta key (`request_timeout` or similar — check Atlas's docs). Atlas's HTTP client (Guzzle or whatever it uses) enforces the deadline at the network layer. If the LLM provider takes too long, the HTTP call fails with a timeout, the executor's existing exception path persists `failed`, and the supervisor handles it through normal failure handling.

**This means:** the timeout applies to the *Atlas call*, not to arbitrary in-process work. That's exactly the right semantic — the only thing in a step that can take significant time is the LLM call. If a contributor adds in-process work to a step in the future, that work is on them.

### Verification of the Atlas timeout API

Before implementing, confirm Atlas exposes a per-request timeout. Check `reference/atlas-3.0.0/src/Pending/AgentRequest.php` for a `withTimeout()` method or similar. If it doesn't exist, F9 reduces to "document that timeout is currently a no-op and add a tracking issue to upstream Atlas." Don't ship F9 without confirming Atlas supports it.

### Files modified

- `src/Execution/AtlasStepExecutor.php` — in `execute()`, read `$input->meta['timeout']` (passed through from `RunProcessor::buildStepInput`), call the appropriate Atlas request method to set the deadline.
- `src/Engine/RunProcessor.php`, `buildStepInput()` (lines 92-118) — add `'timeout' => $stepDefinition->timeout` to the meta array.
- (No changes to the validator — it already checks `timeout > 0`.)

### Tests to add

#### `tests/Feature/StepTimeoutTest.php` (new)

- `it passes the step timeout to the Atlas request` — bind a recording Atlas fake, set up a step with `timeout: 30`, call `execute()`, assert the fake recorded the timeout.
- `it passes the merged default timeout when the step does not specify one` — defaults `{timeout: 60}`, no step timeout, assert the fake recorded 60.
- `it does not pass a timeout when neither step nor defaults specify one` — assert the fake recorded null.
- `it persists the step as failed when Atlas raises a timeout exception` — bind an Atlas fake that throws a timeout exception, run `continueRun`, assert the step is `failed` with the timeout error in `step.error`.
- `it routes a timeout failure through the failure handler matcher like any other error` — set up a `match: timeout` failure handler with `action: retry`, assert the supervisor decision is `retry`.

### Documentation updates

- `README.md` — remove timeout from any "not enforced" lists.
- `CLAUDE.md` — drop "defaults.timeout not enforced" from the inert-fields list. Add a note: "step `timeout` is passed to Atlas as a per-request HTTP deadline. It applies to the LLM call only, not to arbitrary in-process work."
- `docs/package/WORKFLOW_SETS.md`:
  - Update the `timeout` row in Step Fields from "Validated and preserved, not currently enforced as execution timeout" to "Enforced as a per-request HTTP deadline by the Atlas executor."
- `CHANGELOG.md` — "step `timeout` is now enforced as a per-request deadline at the Atlas HTTP layer."

### Verification

```bash
composer test --filter=StepTimeoutTest
composer test
composer analyse
```

### Rollback

`git revert`. Backwards compatible.

---

## F10 — Per-step `on_fail` consumption

**Severity:** 🟡 P1
**Effort:** M
**Depends on:** F4 (the inert-on_fail tripwire test exists to flip)
**Blocks:** nothing

### Why

`StepDefinitionData::$on_fail` is validated as a transition target (must point to a known step or one of `complete`/`discard`/`fail`/`cancel`) and preserved through compilation, but `Supervisor::handleFailure()` never reads it. Failure routing is entirely driven by the global `failure_handlers` block. This means a workflow author who writes `on_fail: cleanup-step` reasonably expects that step to run on failure — and gets nothing.

### Design decision

`on_fail` is a **fallback** transition target that fires when:

1. No `failure_handlers` block matches the step's error AND
2. No escalation is configured OR escalation returns `fail`

In other words: failure handlers run first, then escalation, then `on_fail` as a last resort before truly failing the run.

This ordering is:

```
failed → match handler? → run handler action → done
       ↓ no match
       → retry budget remaining + escalation configured? → escalate → done
       ↓ no escalation OR escalation returned fail
       → step has on_fail target? → transition to on_fail target → done
       ↓ no on_fail
       → run is failed
```

### Files modified

- `src/Engine/Supervisor.php`, `handleFailure()` (search for the existing logic that runs handlers and escalation, around lines 234-320) — after the existing escalation branch returns `fail` (or after no handler matches and no escalation is configured), check `$stepDefinition->on_fail`. If it's set, treat it as a transition target the same way `advance()` treats `on_success`. Reuse `advance()` if possible — the transition logic is identical, just driven by a different field.

The cleanest implementation might be to extract the transition logic from `advance()` into a private `transitionTo(string $target, ...)` helper that both `advance` and the new `on_fail` path call.

### Tests to add

#### `tests/Feature/OnFailTransitionTest.php` (new)

- `it transitions to the on_fail target when no failure handler matches and no escalation is configured` — set up a step with `on_fail: cleanup` pointing to a cleanup step. Trigger a failure with no matching handler. Assert the run transitions to `cleanup` and is in status `running`.
- `it transitions to on_fail terminal target (complete) when configured` — set up a step with `on_fail: complete`, trigger failure, assert the run is `completed`.
- `it transitions to on_fail terminal target (cancel) when configured`
- `it transitions to on_fail terminal target (fail) when configured` — same as no on_fail; explicit assertion for clarity.
- `it does not transition to on_fail when a failure handler matches first` — set up both a matching handler with `action: skip` and an `on_fail` target. Assert the handler runs and the on_fail target does NOT.
- `it does not transition to on_fail when escalation returns retry` — set up escalation that returns `retry`, assert the retry path runs and the on_fail target does NOT.
- `it transitions to on_fail when escalation returns fail` — set up escalation that returns `fail`, assert the on_fail target runs.

#### Existing tests to flip

- `tests/Feature/InertFieldRegressionTest.php` — flip `it does not consume per-step on_fail as a transition target today` into the active assertion above (or just delete it since the new tests cover it more thoroughly).

### Documentation updates

- `README.md` — remove on_fail from any "not consumed" lists.
- `CLAUDE.md` — drop on_fail from the inert-fields list. Add a sentence to the Engine layer description: "per-step `on_fail` is consumed by `Supervisor::handleFailure()` as a fallback transition after failure handlers and escalation have been exhausted."
- `docs/package/WORKFLOW_SETS.md`:
  - Update the `on_fail` row in Step Fields from "Validated and preserved, not consumed by runtime transitions today" to "Consumed by the supervisor as a fallback transition after failure handlers and escalation are exhausted."
  - Add a new section "Failure Routing Order" documenting the cascade: handlers → escalation → on_fail → fail.
- `CHANGELOG.md` — "per-step `on_fail` is now consumed by the supervisor as a fallback transition target after failure handlers and escalation are exhausted."

### Verification

```bash
composer test --filter=OnFailTransitionTest
composer test
composer analyse
```

### Rollback

`git revert`. Backwards compatible — workflows without `on_fail` are unaffected.

---

# Verification matrix

After **every** F-task lands, run the full battery and confirm green:

```bash
composer test          # Pest, all green, growing test count
composer analyse       # PHPStan level 5, 0 errors
vendor/bin/pint --test # 0 violations (after F5)
```

After **all** Phase A → D tasks land, the expected delta vs main:

| Surface | Before | After |
|---|---|---|
| Test count | 72 | ~120 (estimate, +50 across all phases) |
| PHPStan baseline | empty | empty (still) |
| Pint violations | ~35 files | 0 |
| Inactive definition fields | 5 (parallel/foreach/tools/provider_tools/on_fail/defaults/timeout) | 1 (parallel/foreach only — F11 milestone) |
| Production-safety holes | 2 (lock race, retry hot loop) | 0 |
| Dead-scaffolding files | configure.php, NullRunLockProvider as default | 0 |
| Documentation drift | several "not yet" items that are now active | 0 |

---

# Rollout order and dependencies

```
F1 (locks + controller centralization)
 │
 ├──► F2 (retry_after persistence)
 │
 ├──► F3 (IdempotencyGuard tests)
 │
 ├──► F4 (inert-field tripwires) ──┐
 │                                 │
 │                                 ├──► F12 (tools/provider_tools)
 │                                 │
 │                                 ├──► F8 (defaults merge) ──► F9 (timeout enforcement)
 │                                 │
 │                                 └──► F10 (on_fail consumption)
 │
 ├──► F6 (defensive branch tests)
 │
 ├──► F5 (Pint format pass + CI gate)
 │
 └──► F7 (delete configure.php)
```

**Critical path:** F1 → F2 → F4 → F8 → F9. Everything else can interleave.

**Recommended order for a single contributor working linearly:**

1. F1 (largest, sets up the safety net)
2. F2 (small, lands on top of F1's exception infrastructure)
3. F3, F6 (small test additions, no production code)
4. F5, F7 (cleanup, get a clean tree before features)
5. F4 (tripwires before features that flip them)
6. F12 (smallest feature, pre-built)
7. F8 (compiles defaults into steps)
8. F9 (uses F8's merged values)
9. F10 (independent feature; could land any time after F4)

---

# Findings provenance

This plan is grounded in a four-agent validation pass run on 2026-04-07:

- **Agent 1** validated the inert-field claims in CLAUDE.md against the actual code (`parallel`, `foreach`, `tools`, `provider_tools`, `on_fail`, `defaults`, `defaults.timeout`) — all confirmed inert.
- **Agent 2** validated the execution-model claims (`/start` does not auto-execute, `/retry` does not auto-execute, sync execution, `state.driver` inert, `escalation.agent` active) — all confirmed.
- **Agent 3** validated the auxiliary surface (events, locks, idempotency, configure.php, facade) and surfaced two critical findings: `RunLockProvider` is dead scaffolding, retry `delay` is returned but not enforced, `IdempotencyGuard` has zero direct test coverage.
- **Agent 4** mapped test coverage and surfaced gaps (no negative regressions for inert fields, no direct `IdempotencyGuard` tests, two untested defensive branches in `Supervisor`, ~35 Pint violations, all 72 tests passing, PHPStan clean).

A fifth synthesis pass (this document) classified each finding by severity and effort, ordered the work, and identified the controller-duplication wart in `WorkflowController::resume`/`retry`/`cancel` that F1's lock work surfaces as a prerequisite refactor.

The conductor-tools example at `/home/shawn/workspace/laravel-projects/conductor-tools/` was reviewed and verified against `reference/atlas-3.0.0/` — Atlas 3.0 supports `withTools()` and `withProviderTools()` at `src/Pending/AgentRequest.php:190` and `:202`, so F12 is a clean drop-in.

---

# Out of scope (deferred to F11 milestone)

F11 — `parallel: true` + `foreach` fan-out — is its own feature with its own design pass:

- New contract: `ParallelExecutionStrategy` with three implementations: `JobBatchParallelStrategy` (default, uses Laravel `Bus::batch`), `SyncSequentialParallelStrategy` (tests), and optionally `AmpProcessParallelStrategy` (separate satellite package).
- New persistence semantics for `step_runs.batch_index` (the column already exists for this purpose).
- New supervisor advance logic for "wait for all batch items to complete, then transition."
- Job class for individual batch items.
- Tests for batch dispatch, partial failure, retry of failed items.
- Documentation overhaul of WORKFLOW_SETS.md's parallel section.

When F11 lands, the F4 tripwire test (`it does not fan out parallel/foreach steps today`) gets flipped to the active assertion.

F11 should not be bundled with this remediation pass. It's a real feature, not a gap-fill, and combining the two scopes would muddy review and slow shipping.
