# Laravel Conductor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `entrepeneur4lyf/laravel-conductor` as a Laravel 13 package that consumes YAML/JSON workflow definitions, freezes them into compiled snapshots, executes Atlas-backed steps with structured output, persists a revisioned workflow dossier with optimistic concurrency, and advances runs through explicit continuation boundaries including wait/resume semantics.

**Architecture:** Workflow authoring stays declarative. At initialization, Conductor validates the authored definition, compiles a frozen snapshot, creates a dossier with `revision = 1`, and only then dispatches work. Atlas remains the required step-execution substrate, but Conductor owns state, revision checks, exact disposition rules, idempotency guards, and operator-facing endpoints. The source app remains a pattern reference only; the runtime contract is defined by the local design spec and runtime semantics spec.

**Tech Stack:** Laravel 13, PHP 8.3+, AtlasPHP 3.x, Spatie Laravel Data 4.x, Symfony YAML, Twig, JSON Schema validation, Pest, Orchestra Testbench.

## Document Set and Reading Order

This implementation plan is intentionally not the only source of truth. The package contract is split across three local documents:

1. `docs/superpowers/specs/2026-04-06-laravel-conductor-design.md`
   This defines package goals, boundaries, public surface, and architecture.
2. `docs/superpowers/specs/2026-04-06-laravel-conductor-runtime-semantics.md`
   This defines the runtime invariants, dossier mutation rules, disposition ordering, stale-event handling, and wait/resume semantics.
3. `docs/superpowers/plans/2026-04-06-laravel-conductor-implementation.md`
   This maps the design and runtime semantics into execution tasks, file ownership, and verification work.

If a requirement appears "missing" from this plan, check whether it already lives in the design spec or runtime semantics spec before treating it as dropped scope. If a runtime-critical rule is not explicit in any of the three documents, add it before implementation continues.

## Non-Negotiable Runtime Invariants

These rules apply to every task in this plan:

- Authored workflow data and compiled workflow data are distinct layers.
- Authored definitions may contain symbolic references such as `prompt_template` and `output_schema`.
- Compiled snapshots must freeze resolved prompt template paths and resolved schema paths together with `compiled_at` and `source_hash`.
- Resolved paths alone are not sufficient freeze semantics if later execution still reads mutable files. The compiled snapshot must also carry immutable prompt/schema artifacts or their equivalent frozen content for in-flight runs.
- State is only authoritative after a successful persisted write.
- Every successful run mutation increments `revision` by exactly `1`.
- Terminal runs are immutable unless an explicit operator flow says otherwise.
- Workers and step executors never mutate dossier state directly.
- AI escalation is a fallback after deterministic evaluation, never a shortcut around it.

## Cross-Task Constraints

- Do not use loose arrays where the spec already names a runtime concept that should become a DTO.
- Do not let tests prove only hydration mechanics; they must also prove the semantic boundary for the layer under test.
- If implementation or review reveals that this plan is omitting runtime-critical detail, expand the plan instead of hand-waving the gap.

---

## File Structure

- `composer.json`
- `config/conductor.php`
- `routes/api.php`
- `src/LaravelConductorServiceProvider.php`
- `src/Conductor.php`
- `src/Facades/Conductor.php`
- `src/Contracts/DefinitionRepository.php`
- `src/Contracts/WorkflowStateStore.php`
- `src/Contracts/WorkflowStepExecutor.php`
- `src/Contracts/RunLockProvider.php`
- `src/Data/WorkflowDefinitionData.php`
- `src/Data/StepDefinitionData.php`
- `src/Data/FailureHandlerData.php`
- `src/Data/CompiledWorkflowData.php`
- `src/Data/WorkflowRunStateData.php`
- `src/Data/StepExecutionStateData.php`
- `src/Data/StepInputData.php`
- `src/Data/StepOutputData.php`
- `src/Data/SupervisorDecisionData.php`
- `src/Data/DispositionData.php`
- `src/Data/TimelineEntryData.php`
- `src/Data/WaitStateData.php`
- `src/Definitions/YamlWorkflowDefinitionRepository.php`
- `src/Definitions/WorkflowDefinitionValidator.php`
- `src/Definitions/WorkflowCompiler.php`
- `src/Engine/WorkflowEngine.php`
- `src/Engine/Supervisor.php`
- `src/Engine/FailureHandlerMatcher.php`
- `src/Engine/QualityRuleEvaluator.php`
- `src/Engine/SchemaValidator.php`
- `src/Engine/TemplateRenderer.php`
- `src/Engine/IdempotencyGuard.php`
- `src/Persistence/Models/PipelineRun.php`
- `src/Persistence/Models/StepRun.php`
- `src/Persistence/DatabaseWorkflowStateStore.php`
- `src/Persistence/OptimisticRunMutator.php`
- `src/Execution/AtlasStepExecutor.php`
- `src/Http/Controllers/WorkflowController.php`
- `src/Http/Requests/StartWorkflowRequest.php`
- `src/Http/Requests/ResumeWorkflowRequest.php`
- `src/Http/Requests/RetryWorkflowRequest.php`
- `src/Events/WorkflowStarted.php`
- `src/Events/RunWaiting.php`
- `src/Events/StepRetrying.php`
- `src/Events/WorkflowCompleted.php`
- `src/Events/WorkflowFailed.php`
- `src/Events/WorkflowCancelled.php`
- `database/migrations/2026_04_06_000001_create_pipeline_runs_table.php`
- `database/migrations/2026_04_06_000002_create_step_runs_table.php`
- `stubs/workflow.stub.yaml`
- `stubs/schema.stub.json`
- `stubs/prompt.stub.md.j2`
- `tests/Feature/PackageBootTest.php`
- `tests/Feature/DataObjectsTest.php`
- `tests/Feature/WorkflowDefinitionValidationTest.php`
- `tests/Feature/WorkflowStateStoreTest.php`
- `tests/Feature/OptimisticConcurrencyTest.php`
- `tests/Feature/StructuredAtlasExecutionTest.php`
- `tests/Feature/RetryAndDispositionTest.php`
- `tests/Feature/WaitStateSemanticsTest.php`
- `tests/Feature/StartWorkflowTest.php`
- `tests/Feature/ResumeWorkflowTest.php`
- `tests/Feature/EndToEndWorkflowTest.php`

## Task 1: Replace the Skeleton Package Identity

Status: completed in the current working tree, but do not consider it done until the revised docs are committed with the final implementation.

Required outcome:
- package identity, service provider, facade, config, migration stubs, and test harness are fully switched away from the skeleton.

Verification:
- `rtk composer validate --no-check-publish`
- `rtk vendor/bin/pest tests/Feature/PackageBootTest.php tests/ArchTest.php -v`

## Task 2: Build the Typed DTO Layer Around the Runtime Semantics

**Files:**
- Create: `src/Data/WorkflowDefinitionData.php`
- Create: `src/Data/StepDefinitionData.php`
- Create: `src/Data/FailureHandlerData.php`
- Create: `src/Data/CompiledWorkflowData.php`
- Create: `src/Data/WorkflowRunStateData.php`
- Create: `src/Data/StepExecutionStateData.php`
- Create: `src/Data/StepInputData.php`
- Create: `src/Data/StepOutputData.php`
- Create: `src/Data/SupervisorDecisionData.php`
- Create: `src/Data/DispositionData.php`
- Create: `src/Data/TimelineEntryData.php`
- Create: `src/Data/WaitStateData.php`
- Modify: `tests/TestCase.php` if needed for clean Spatie Data bootstrap
- Test: `tests/Feature/DataObjectsTest.php`

- [ ] Write the failing hydration test for `WorkflowDefinitionData`, `CompiledWorkflowData`, and `WorkflowRunStateData`.
- [ ] Run: `rtk vendor/bin/pest tests/Feature/DataObjectsTest.php -v`
Expected: fail before DTOs exist or before clean test bootstrap is in place.
- [ ] Implement DTOs with these non-negotiable fields:
  - `StepDefinitionData`: `agent`, `wait_for`, `context_map`, `parallel`, `foreach`
  - `CompiledWorkflowData`: frozen steps, failure handlers, `compiled_at`, `source_hash`
  - `WorkflowRunStateData`: `workflow_version`, `revision`, `snapshot`, optional `wait`
  - `StepExecutionStateData`: `attempt`, `batch_index`, `supervisor_decision`, `prompt_override`
  - `StepInputData`: rendered prompt, payload, previous output, meta
  - `StepOutputData`: status, payload, error, metadata
- [ ] Make the authored-vs-compiled distinction explicit in the DTO contract:
  - authored step data may keep symbolic `prompt_template` and `output_schema`
  - compiled step data must be able to expose resolved `prompt_template_path` and `output_schema_path`
  - the hydration test must assert that compiled data is not modeled as a blind reuse of authored-reference semantics
- [ ] Ensure `WorkflowRunStateData` can round-trip:
  - `snapshot` as a nested `CompiledWorkflowData`
  - `wait` as a nested `WaitStateData`
  - state with `revision` present even when optional output fields are absent
- [ ] Ensure `StepExecutionStateData` nests typed `StepInputData`, `StepOutputData`, and `SupervisorDecisionData`.
- [ ] Ensure Spatie Laravel Data is bootstrapped cleanly through Testbench setup, not inline vendor config loading inside a test body.
- [ ] Run: `rtk vendor/bin/pest tests/Feature/DataObjectsTest.php -v`
Expected: pass.

## Task 3: Implement Definition Loading, Validation, and Snapshot Compilation

**Files:**
- Create: `src/Contracts/DefinitionRepository.php`
- Create: `src/Definitions/YamlWorkflowDefinitionRepository.php`
- Create: `src/Definitions/WorkflowDefinitionValidator.php`
- Create: `src/Definitions/WorkflowCompiler.php`
- Create: `src/Engine/TemplateRenderer.php`
- Create: `src/Engine/SchemaValidator.php`
- Modify: `src/LaravelConductorServiceProvider.php`
- Test: `tests/Feature/WorkflowDefinitionValidationTest.php`

- [ ] Write a failing test that loads a fixture workflow and asserts:
  - duplicate step ids are rejected
  - prompt and schema paths resolve
  - `agent` is the authored field name
  - the compiled snapshot is frozen with `source_hash` and `compiled_at`
  - the compiled snapshot stores resolved prompt/schema paths, not only raw authored references
  - changing execution-critical prompt/schema assets would be detectable from the compiled snapshot contract rather than silently redefined through the filesystem
- [ ] Run: `rtk vendor/bin/pest tests/Feature/WorkflowDefinitionValidationTest.php -v`
Expected: fail before repository/compiler exist.
- [ ] Implement `DefinitionRepository::load()` for YAML/JSON authored definitions.
- [ ] Ensure the definition-loading contract exposes the resolved source path needed for downstream validation/compilation of named workflows.
- [ ] Implement `WorkflowDefinitionValidator` to reject invalid definitions before execution.
- [ ] Implement `WorkflowCompiler` to freeze execution-critical data into `CompiledWorkflowData`.
- [ ] `WorkflowCompiler` must make the authored-to-compiled transition explicit:
  - resolve prompt template locations
  - resolve schema locations
  - resolve failure-handler prompt template locations when handlers define a prompt template
  - freeze immutable prompt/schema artifacts in the compiled snapshot so later execution does not depend on mutable source files
  - preserve execution-critical values for in-flight runs
  - avoid letting mutable source files redefine an already-started run
- [ ] `WorkflowDefinitionValidator` must reject at least:
  - duplicate step ids
  - missing prompt/schema assets
  - invalid JSON schema files
  - `on_success` / `on_fail` targets that are neither known step ids nor supported terminal values
  - failure-handler actions outside the supported disposition set
  - invalid `parallel` / `foreach` combinations that would be guaranteed to fail later
  - invalid numeric control values such as negative retries, timeout, or delay
- [ ] Be explicit about unsupported asset graph features in v1:
  - if frozen prompt rendering cannot safely preserve Twig include/inheritance graphs, reject those patterns during validation
  - if frozen schema validation cannot safely preserve external `$ref` graphs, reject those schemas during validation
- [ ] Keep the frozen snapshot usable by later runtime work:
  - runtime helpers should expose content-based rendering/validation entry points for frozen prompt/schema contents
  - later execution tasks must not be forced back onto live disk reads just because the helper APIs only accept paths
- [ ] Run: `rtk vendor/bin/pest tests/Feature/WorkflowDefinitionValidationTest.php -v`
Expected: pass.

## Task 4: Implement the DB-Backed Dossier Store and Optimistic Concurrency

**Files:**
- Create: `src/Contracts/WorkflowStateStore.php`
- Create: `src/Contracts/RunLockProvider.php`
- Create: `src/Persistence/Models/PipelineRun.php`
- Create: `src/Persistence/Models/StepRun.php`
- Create: `src/Persistence/DatabaseWorkflowStateStore.php`
- Create: `src/Persistence/OptimisticRunMutator.php`
- Create: `database/migrations/2026_04_06_000001_create_pipeline_runs_table.php`
- Create: `database/migrations/2026_04_06_000002_create_step_runs_table.php`
- Test: `tests/Feature/WorkflowStateStoreTest.php`
- Test: `tests/Feature/OptimisticConcurrencyTest.php`

- [ ] Write a failing dossier-store test that proves:
  - a run dossier is stored with `revision = 1`
  - snapshot JSON is stored with the run
  - step runs can be rehydrated as typed data
- [ ] Write a failing optimistic concurrency test that proves:
  - conditional update succeeds when `revision` matches
  - conditional update is rejected when `revision` is stale
  - stale writes do not silently overwrite dossier state
- [ ] Run: `rtk vendor/bin/pest tests/Feature/WorkflowStateStoreTest.php tests/Feature/OptimisticConcurrencyTest.php -v`
Expected: fail before the store exists.
- [ ] Create `pipeline_runs` with at least:
  - `id`, `workflow`, `workflow_version`, `revision`, `status`, `current_step_id`, `input`, `snapshot`, `wait`, `output`, timestamps
- [ ] Create `step_runs` with at least:
  - `pipeline_run_id`, `step_definition_id`, `status`, `attempt`, `batch_index`, `input`, `output`, `error`, `prompt_override`, `supervisor_decision`, `supervisor_feedback`, timestamps
- [ ] Implement `DatabaseWorkflowStateStore` as the default V2 dossier store.
- [ ] Implement `OptimisticRunMutator` with `WHERE id = ? AND revision = ?` semantics.
- [ ] Store `snapshot` and `wait` as structured JSON that can round-trip into typed DTOs without test-only bootstrap hacks or ad hoc array mappers.
- [ ] Run: `rtk vendor/bin/pest tests/Feature/WorkflowStateStoreTest.php tests/Feature/OptimisticConcurrencyTest.php -v`
Expected: pass.

## Task 5: Implement Atlas Structured Step Execution

**Files:**
- Create: `src/Contracts/WorkflowStepExecutor.php`
- Create: `src/Execution/AtlasStepExecutor.php`
- Test: `tests/Feature/StructuredAtlasExecutionTest.php`

- [ ] Write a failing test that proves the executor uses `withSchema(...)->asStructured()` when `output_schema_path` exists in `StepInputData::$meta`.
- [ ] Run: `rtk vendor/bin/pest tests/Feature/StructuredAtlasExecutionTest.php -v`
Expected: fail before the executor exists.
- [ ] Implement `WorkflowStepExecutor::execute(string $agent, StepInputData $input): StepOutputData`.
- [ ] Implement `AtlasStepExecutor` so it:
  - prefers structured output when schema exists
  - falls back to text only when no schema path is available
  - returns typed `StepOutputData`
  - carries Atlas usage data in metadata
- [ ] The executor must consume resolved schema path data from the compiled/runtime layer, not reinterpret raw authored schema references at execution time.
- [ ] Run: `rtk vendor/bin/pest tests/Feature/StructuredAtlasExecutionTest.php -v`
Expected: pass.

## Task 6: Implement the Workflow Engine, Supervisor, and Exact Disposition Rules

**Files:**
- Create: `src/Engine/WorkflowEngine.php`
- Create: `src/Engine/Supervisor.php`
- Create: `src/Engine/FailureHandlerMatcher.php`
- Create: `src/Engine/QualityRuleEvaluator.php`
- Create: `src/Engine/IdempotencyGuard.php`
- Test: `tests/Feature/RetryAndDispositionTest.php`
- Test: `tests/Feature/WaitStateSemanticsTest.php`

- [ ] Write failing tests for:
  - deterministic evaluation order
  - retry budget exhaustion falling through to fail
  - `retry_with_prompt` storing `prompt_override`
  - `skip` on false condition
  - `wait` creating `resume_token` and `waiting` run state
  - duplicate `EvaluateStepJob` becoming a no-op
  - stale continuation against terminal runs becoming a no-op
- [ ] Run: `rtk vendor/bin/pest tests/Feature/RetryAndDispositionTest.php tests/Feature/WaitStateSemanticsTest.php -v`
Expected: fail before engine/supervisor exist.
- [ ] Implement `WorkflowEngine::start()` so it:
  - validates and compiles definition
  - creates dossier with `pending -> initializing`
  - persists snapshot and `revision = 1`
  - creates initial `StepRun`
  - persists state before dispatch
- [ ] Implement `Supervisor` with exact semantics from the runtime spec:
  - guard checks
  - schema validation
  - quality rules
  - handler match
  - AI escalation only when no deterministic path exists
  - exact `advance`, `retry`, `retry_with_prompt`, `skip`, `wait`, `fail`, `complete`, `cancel`
- [ ] Implement `IdempotencyGuard` for duplicate and stale evaluation protection.
- [ ] Explicitly cover no-op behavior for:
  - terminal runs
  - stale `current_step_id`
  - already-decided `StepRun`
  - delayed retry jobs aimed at a no-longer-pending attempt
- [ ] Run: `rtk vendor/bin/pest tests/Feature/RetryAndDispositionTest.php tests/Feature/WaitStateSemanticsTest.php -v`
Expected: pass.

## Task 7: Expose Start, Status, Resume, Retry, and Cancel Endpoints

**Files:**
- Create: `routes/api.php`
- Create: `src/Http/Controllers/WorkflowController.php`
- Create: `src/Http/Requests/StartWorkflowRequest.php`
- Create: `src/Http/Requests/ResumeWorkflowRequest.php`
- Create: `src/Http/Requests/RetryWorkflowRequest.php`
- Test: `tests/Feature/StartWorkflowTest.php`
- Test: `tests/Feature/ResumeWorkflowTest.php`

- [ ] Write failing endpoint tests for:
  - `POST /api/conductor/start`
  - `GET /api/conductor/runs/{runId}`
  - `POST /api/conductor/runs/{runId}/resume`
  - `POST /api/conductor/runs/{runId}/retry`
  - `POST /api/conductor/runs/{runId}/cancel`
- [ ] Ensure retry and cancel endpoints require the expected revision and return `409` on mismatch.
- [ ] Ensure resume requires a valid `resume_token`.
- [ ] Run: `rtk vendor/bin/pest tests/Feature/StartWorkflowTest.php tests/Feature/ResumeWorkflowTest.php -v`
Expected: fail before controller/routes exist.
- [ ] Implement the controller and request validators without inventing UI behavior.
- [ ] Run: `rtk vendor/bin/pest tests/Feature/StartWorkflowTest.php tests/Feature/ResumeWorkflowTest.php -v`
Expected: pass.

## Task 8: Add Commands, Events, and Stubs

**Files:**
- Create: `src/Commands/ValidateWorkflowCommand.php`
- Create: `src/Commands/MakeWorkflowCommand.php`
- Create: `src/Commands/WorkflowStatusCommand.php`
- Create: `src/Commands/RetryWorkflowCommand.php`
- Create: `src/Commands/CancelWorkflowCommand.php`
- Create: `src/Events/WorkflowStarted.php`
- Create: `src/Events/RunWaiting.php`
- Create: `src/Events/StepRetrying.php`
- Create: `src/Events/WorkflowCompleted.php`
- Create: `src/Events/WorkflowFailed.php`
- Create: `src/Events/WorkflowCancelled.php`
- Create: `stubs/workflow.stub.yaml`
- Create: `stubs/schema.stub.json`
- Create: `stubs/prompt.stub.md.j2`

- [ ] Add command registration and smoke-test it via `artisan list`.
- [ ] Add lifecycle events for the exact runtime transitions.
- [ ] Add stubs that use `agent`, not `worker`, and show `context_map`, `wait_for`, and schema references.
- [ ] Run: `rtk php vendor/bin/testbench package:discover --ansi`
Expected: pass.

## Task 9: Prove the End-to-End Package Flow

**Files:**
- Create: `tests/Fixtures/workflows/content-pipeline.yaml`
- Create: `tests/Fixtures/workflows/prompts/*.md.j2`
- Create: `tests/Fixtures/workflows/schemas/*.json`
- Test: `tests/Feature/EndToEndWorkflowTest.php`

- [ ] Write an end-to-end test that proves:
  - workflow start creates a dossier with `revision = 1`
  - compiled snapshot is stored on the run
  - compiled snapshot stores resolved prompt/schema paths distinct from authored references
  - Atlas step execution uses structured output
  - supervisor advances correctly
  - stale duplicate evaluation is a no-op
  - wait/resume flow works with `resume_token`
  - terminal runs reject further mutations
- [ ] Run: `rtk vendor/bin/pest tests/Feature/EndToEndWorkflowTest.php -v`
Expected: fail before all pieces are integrated.
- [ ] Integrate the package pieces until the end-to-end test passes.
- [ ] Run:
  - `rtk vendor/bin/pest -v`
  - `rtk vendor/bin/phpstan analyse`
  - `rtk vendor/bin/pint --test`
Expected: all pass.

## Task 10: Final Docs and Release Cleanup

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `phpstan.neon.dist`
- Modify: `phpunit.xml.dist`

- [ ] Rewrite the README around the actual package contract:
  - authored YAML/JSON definitions
  - Atlas dependency
  - revisioned dossier semantics
  - compiled snapshots
  - wait/resume lifecycle
- [ ] Remove any stale references to file-backed canonical state as the default.
- [ ] Run the final full verification pass.

## Spec Coverage Self-Review

- Package identity and bootstrap: Task 1.
- Typed DTOs with runtime semantics fields: Task 2.
- YAML/JSON loading plus compiled snapshots: Task 3.
- DB-backed dossier with optimistic concurrency: Task 4.
- Atlas structured step execution: Task 5.
- Exact disposition ordering and idempotency: Task 6.
- Resume / operator endpoints: Task 7.
- Commands, events, and stubs: Task 8.
- End-to-end proof of runtime semantics: Task 9.
- Documentation sync: Task 10.

## Open Notes

- The earlier file-backed-state-first plan is obsolete.
- The local authoritative runtime document is:
  `docs/superpowers/specs/2026-04-06-laravel-conductor-runtime-semantics.md`
- The design and runtime specs together carry substantial detail that this plan does not repeat verbatim. Shorter plan length does not imply reduced package scope.
- Do not resume Task 2 implementation from the old plan text. Resume only against this revised plan.
- The plan was previously over-compressed. When review exposes a missing runtime detail, expand the plan and tests before moving on.
