# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`entrepeneur4lyf/laravel-conductor` is an Atlas-native workflow orchestration package for Laravel 13. It is a *package*, not an application — it ships a service provider, migrations, routes, artisan commands, and a `Conductor` facade for host applications to consume.

Stack: PHP `^8.3`, Laravel `^13.0`, `atlas-php/atlas` `^3.0`, `spatie/laravel-data`, `twig/twig`, `justinrainbow/json-schema`, `symfony/yaml`. Tested via `pestphp/pest` `^4.0` on `orchestra/testbench` `^11`.

## Commands

```bash
composer test                  # Pest test suite (alias: vendor/bin/pest)
vendor/bin/pest --filter=Foo   # Run a single test by name pattern
vendor/bin/pest tests/Feature/EndToEndWorkflowTest.php   # Run a single file
composer analyse               # PHPStan level 5 across src/, config/, database/
composer format                # Pint
composer prepare               # Re-runs testbench package:discover (also runs post-autoload-dump)
```

CI matrix runs `vendor/bin/pest --ci` against PHP 8.3/8.4 × Laravel 12/13 × prefer-lowest/prefer-stable on Ubuntu and Windows. Keep changes compatible with that matrix.

The README references `rtk vendor/bin/pest` as the verified green command — `rtk` is a token-optimizing proxy and is optional locally.

## Architecture

The package is best understood as four cooperating layers around a single `WorkflowRunStateData` dossier that flows through every persistence and execution boundary.

### 1. Definitions (`src/Definitions/`)

Workflows live as YAML or JSON in a "workflow set" directory tree (default `base_path('workflows')`, configurable via `conductor.definitions.paths`). The tree contains the definition file, a `prompts/` subtree of Twig templates, and a `schemas/` subtree of JSON Schemas.

- `YamlWorkflowDefinitionRepository` resolves a bare workflow name, relative path, or absolute path. For bare names it tries `.yaml`, `.yml`, then `.json` across configured paths.
- `WorkflowDefinitionValidator` enforces structural rules (unique step ids, transition targets, retry/timeout bounds, parallel/foreach pairing, failure-handler shape).
- `WorkflowCompiler` resolves prompt and schema references **inside the workflow tree**, freezes their full contents into `CompiledWorkflowData`, and computes a `source_hash`. This compiled snapshot is stored on the run dossier so execution never re-reads mutable source files.

Both `TemplateRenderer` (Twig) and `SchemaValidator` reject anything that escapes the workflow tree, including Twig `include`/`extends`/`source()` references and external JSON Schema `$ref`s. Local fragment refs like `#/properties/foo` are allowed.

### 2. Persistence (`src/Persistence/`)

Two tables are migrated by the package: `pipeline_runs` (the dossier) and `step_runs` (per-attempt step state).

- `DatabaseWorkflowStateStore` (the only bound `WorkflowStateStore` today) hydrates and dehydrates `WorkflowRunStateData` against those tables.
- `OptimisticRunMutator` enforces revision discipline: `create()` requires `revision === 1`, `update()` requires `revision === expected + 1` and uses a `WHERE revision = ?` predicate that throws if the row was mutated underneath. **Every state mutation increments `revision` by exactly 1** — preserve this invariant when adding new mutators.
- Step rows are upserted in place using the unique key `(pipeline_run_id, step_definition_id, attempt, batch_index)`. Do not reintroduce destructive resync (delete-then-insert) on step rows; the test suite has explicit coverage that step row identity is stable across persists.

### 3. Engine (`src/Engine/`)

Two collaborating services own runtime semantics:

- `WorkflowEngine::start()` loads + compiles a definition, builds the initial dossier, and writes it with `revision = 1`. **It does not execute the first step.**
- `RunProcessor::continueRun()` is the synchronous executor: load run → ask `Supervisor::evaluate()` for a deterministic decision → if `noop`, build `StepInputData`, persist `running`, call the bound `WorkflowStepExecutor`, persist `completed` or `failed`, then re-enter the supervisor and return its decision.
- `Supervisor` is the brain. It dispatches on step status: `pending` may skip on falsy `condition` or enter `waiting` for `wait_for`; `completed` runs JSON Schema validation, then `quality_rules`, then transitions via `on_success`; `failed` runs `FailureHandlerMatcher` (case-insensitive regex against `match`), and on no match — *if retry budget remains* — calls `EscalationEvaluator` which dispatches an Atlas agent (key from `conductor.escalation.agent`, default `conductor-supervisor`). The escalation agent's reply is constrained to `retry`, `skip`, or `fail`; anything else degrades to `fail`.
- `IdempotencyGuard` short-circuits supervisor evaluation when a step's terminal disposition is already recorded, so re-entering `evaluate()` is safe.

When extending the supervisor, remember the canonical decision actions: `advance`, `retry`, `retry_with_prompt`, `skip`, `wait`, `fail`, `complete`, `cancel`, `noop`. Tests assert on these strings.

### 4. Execution (`src/Execution/`)

`AtlasStepExecutor` is the only bound `WorkflowStepExecutor`. It resolves the Atlas agent by the step's `agent` key, attaches `StepInputData::$meta` (which carries `output_schema_path`, `tools`, `provider_tools`, plus the step's own `meta`), and uses `Atlas::asStructured()` when an `output_schema_path` is present, otherwise `asText()`. Structured payloads are returned verbatim; text payloads are wrapped as `{ text: ... }`.

Host applications can rebind `WorkflowStepExecutor` to plug in a non-Atlas executor, but be aware: the executor is called **synchronously inside the HTTP request**. Background dispatch is intentionally not implemented yet.

### Lifecycle / API surface

Routes (prefix `api/conductor`, configurable):

- `POST /start` — initialize, write revision 1, set `current_step_id`. Does not execute.
- `POST /runs/{runId}/continue` — the only endpoint that actually runs a step.
- `GET /runs/{runId}` — read the dossier.
- `POST /runs/{runId}/resume` — for steps with `wait_for`; consumes a `resume_token`, marks the waiting step `completed` with the supplied payload, then re-enters supervisor evaluation.
- `POST /runs/{runId}/retry` — appends a new pending attempt to a `failed` run (caller must pass current `revision`). Does **not** execute the retry; a follow-up `/continue` is required.
- `POST /runs/{runId}/cancel` — terminates an active run.

The same surface is mirrored as artisan commands: `conductor:make-workflow`, `conductor:validate`, `conductor:status`, `conductor:retry`, `conductor:cancel`.

## Things to know before changing things

- **Read `src/Engine/Supervisor.php` before touching any decision logic.** It is the largest and most opinionated file in the package and the test suite (`SupervisorRemediationTest`, `RetryAndDispositionTest`, `EscalationEvaluatorTest`, `RetryBackoffTest`) pins its behavior tightly.
- **Failure handler `delay` is honored via `WorkflowRunStateData::$retry_after`.** The database column is `retry_after` on `pipeline_runs` (cast as `datetime` on the Eloquent model). When a failure-handler-driven retry persists, `Supervisor::retry()` writes `retry_after = now('UTC') + delay` (or `null` when `delay` is 0). `RunProcessor::continueRun()` short-circuits with a `noop` decision while the backoff is active. Every non-retry state write (`persistRunning`, `persistCompleted`, `persistFailed`, `Conductor::resumeRun/retryRun/cancelRun`) clears `retry_after` to `null`. Escalation-driven retries (`Supervisor::retryFromEscalation`) do **not** populate `retry_after` — the AI decides to retry without a backoff.
- **Definition fields that are accepted but not yet executed by the runtime:** `parallel`, `foreach`, `on_fail` transition semantics, `defaults.timeout` enforcement, and `defaults` merge at compile time. They validate and serialize correctly, but adding tests that assume runtime fan-out or `on_fail` consumption will fail. The README's "What It Does Not Yet Do" list is the source of truth — keep it in sync if you implement any of those.
- **Tools and provider_tools are live at execution time.** `StepDefinitionData::$tools` is resolved via `src/Tools/ToolResolver.php` (strategies: explicit `conductor.tools.map`, FQCN passthrough, convention-based lookup under `conductor.tools.namespace`). `StepDefinitionData::$provider_tools` is resolved via `src/Tools/ProviderToolResolver.php` (string type → known Atlas ProviderTool class; object `{type, options}` → class with camelCase constructor args). Both are invoked inside `src/Execution/AtlasStepExecutor.php` via Atlas's `withTools()` / `withProviderTools()`. Resolution happens at **execution time**, not compile time, so a compiled workflow snapshot stays portable across hosts with different tool registrations.
- **`/start` does not auto-execute** and **retries are not auto-queued.** The package is explicit and API-driven by design. Don't "fix" this by quietly dispatching jobs from inside `WorkflowEngine` or `Conductor::retryRun()` without an explicit design discussion.
- **The compiled snapshot is the source of truth at runtime.** Step execution reads `prompt_template_contents` and `output_schema_contents` from the dossier, not from disk. Any new field on `StepDefinitionData` that needs to influence execution must be threaded through `WorkflowCompiler::compileStep()` *and* through `RunProcessor::buildStepInput()`.
- **Optimistic concurrency is non-negotiable.** When you add a write path, route it through `OptimisticRunMutator` (or `WorkflowStateStore::save($state, $expectedRevision)`) and bump `revision` exactly once per persist.
- **Spatie Laravel Data DTOs (`src/Data/`)** are the wire format for the dossier and for HTTP responses. Adding a property requires updating both the DTO and any `from([...])` array spreads (there are several inside `Conductor.php` and `RunProcessor.php` that copy the dossier with `...$run->toArray()`).
- **Tests boot via `orchestra/testbench` against an in-memory SQLite.** `tests/TestCase.php` re-creates the `pipeline_runs` and `step_runs` tables by hand on every test (it does not run package migrations). If you change the migration stubs in `database/migrations/`, mirror those changes in `TestCase::prepareConductorDatabase()` or the suite will drift from production schema.
- **Workflow fixtures live under `tests/Fixtures/workflows/`.** Reuse them when adding feature tests instead of inlining new YAML in test bodies.
- **PHPStan baseline is empty** (`phpstan-baseline.neon` is 0 bytes). Keep it that way — `composer analyse` should remain clean at level 5.
- **Run-level concurrency uses a three-layer defense, not a single lock.** Every mutating run operation (`RunProcessor::continueRun`, `Conductor::resumeRun/retryRun/cancelRun`) is wrapped in `$lockProvider->withLock($runId, fn () => ...)`. The default production binding is `CacheLockRunLockProvider` (backed by `Cache::lock`), and the test suite swaps in `NullRunLockProvider` for determinism (see `tests/TestCase.php`). When you add a new mutating entry point against a single run, route it through `withLock` too — the HTTP layer translates `RunLockedException` into a `423 Locked` response and domain exceptions in `src/Exceptions/` into their respective 4xx responses. Do not reimplement resume/retry/cancel logic directly in the controller; delegate to `Conductor`. Controllers and Artisan commands BOTH must go through `Conductor` — the CLI `conductor:cancel` / `conductor:retry` commands delegate to `Conductor::cancelRun/retryRun` so HTTP and CLI share the same lock boundary. The layered model:
  1. **Cache lock (cheap rejection)** — a short-TTL `Cache::lock` is the first line of defense and rejects obvious concurrent requests with `423`. It is *not* load-bearing for correctness; do not tune `CONDUCTOR_LOCK_TTL` to "exceed your slowest step." The default of 60 seconds is intentional so stuck processes recover quickly.
  2. **Pre-Atlas revision re-check** — immediately before the executor call inside `RunProcessor::continueRun`, the run is re-read from the state store and its `revision` is compared against the locally held value. If they diverge, `RunRevisionMismatchException` is thrown (mapped to `409` by the controller) before any LLM tokens are burned. This catches the case where a previous request's lock TTL expired mid-Atlas-call and a second request advanced the run.
  3. **Optimistic concurrency on the final write** — `OptimisticRunMutator::update()` uses `WHERE revision = ?` and is the authoritative correctness gate. Even if both prior layers were skipped, the final persisted state is always consistent.

  Trade-off this layered model explicitly accepts: a wasted Atlas call is still possible inside the millisecond-window race between layer 2 and the executor invocation, but the final persisted state is always correct. Heartbeat-style auto-renewing locks were considered and rejected (they require POSIX signals, subprocess spawning, or async I/O — none of which are workable inside a synchronous PHP request).

## Reference material

`reference/` contains design notes (`2026-04-06-conductor-runtime-semantics.md`, `2026-04-06-workflow-engine-design.md`) and unzipped copies of upstream dependencies (`atlas-3.0.0/`, `laravel-data-4.21.0/`, `ai-0.4.3/`, `agents-directory/`). Consult these when changing how the package interacts with Atlas or laravel-data — they are the actual sources of those libraries, not summaries.
