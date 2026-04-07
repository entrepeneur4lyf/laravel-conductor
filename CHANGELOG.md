# Changelog

All notable changes to `entrepeneur4lyf/laravel-conductor` will be documented in this file.

## Unreleased (targeted for v2)

- `parallel: true` + `foreach` fan-out execution is the sole v2 scope. The design spec lives at `docs/F11_PLAN.md` (architecture, 8 load-bearing decisions, 10-commit work plan, risks). Until v2 ships, the `tests/Feature/InertFieldRegressionTest.php` tripwire keeps the current no-op semantics pinned.

## [1.0.0] - 2026-04-07

Phase A → D remediation shipped. Every advertised definition field is active except `parallel`/`foreach`, which is deferred to v2.

- per-step `timeout` is now enforced as a per-call HTTP deadline against the Atlas request via `Atlas\Pending\AgentRequest::withTimeout()`. The value is forwarded from `StepDefinitionData::$timeout` (or the merged `defaults.timeout` from F8) through `StepInputData::$meta` and applied inside `AtlasStepExecutor::execute()`. The timeout applies to the LLM round-trip only; in-process work in the same step is not bounded by this value (heartbeat or non-blocking I/O would be needed for that, which is out of scope). Non-positive timeouts are ignored.
- per-step `on_fail` is now consumed by the supervisor as a fallback transition target after failure handlers and escalation are exhausted. Cascade order: matching failure handler runs first → escalation (if retry budget remains) → `on_fail` (if declared) → `fail`. The on_fail target may point at any known step or any of the terminal targets (`complete`, `discard`, `fail`, `cancel`). When a handler matches and resolves the failure (e.g. `skip`), the on_fail target is bypassed.
- workflow root `defaults` block is now merged into individual steps at load time when the step does not declare the same field explicitly. Merge-eligible keys: `retries`, `timeout`, `tools`, `provider_tools`, `meta`. Step values always win over defaults; arrays are replaced (not deep-merged). Step-identity fields (`id`, `agent`, `prompt_template`, `output_schema`) are rejected from the defaults block at load time with a clear error message. The merge happens at the raw array level (in `YamlWorkflowDefinitionRepository`) so the distinction between "explicitly set" and "using the DTO default" is preserved.
- added runtime resolution and invocation of step `tools` and `provider_tools` via Atlas's `withTools()` / `withProviderTools()`. Tool identifiers are resolved at execution time via a new `Entrepeneur4lyf\LaravelConductor\Tools\ToolResolver` (three strategies: explicit `conductor.tools.map`, FQCN passthrough, convention-based lookup under `conductor.tools.namespace`). Provider tool declarations are resolved via `ProviderToolResolver` and accept bare strings (`web_search`) or object forms (`{type, options}`) with automatic snake_case → camelCase constructor-arg translation. Wiring lives in `LaravelConductorServiceProvider` and `src/Execution/AtlasStepExecutor`; a new `conductor.tools` config block configures the namespace and explicit map.
- ran `composer format` across the entire package to clear accumulated Pint style violations (~35 files, cosmetic only: single-line empty bodies, import ordering, unary operator spacing, brace position). Added a new `.github/workflows/pint.yml` CI job so pull requests cannot reintroduce style drift.
- removed the inert `configure.php` post-install scaffolding left over from the Spatie package skeleton. The placeholder strings it searched for have been hard-replaced throughout the package since initial extraction, so the script was dead code.
- failure handler `delay` is now enforced via a persisted `retry_after` timestamp on the run dossier; `/continue` short-circuits with a `noop` decision while the backoff is active. Adds `retry_after` to the `pipeline_runs` table, the `PipelineRun` Eloquent model, and `WorkflowRunStateData`. Every non-retry state transition (success, failure, resume, manual retry, cancel) clears the backoff. Escalation-driven retries do not set `retry_after`.
- added a Cache::lock-backed `RunLockProvider` so concurrent `/continue`, `/resume`, `/retry`, and `/cancel` requests against the same workflow run can no longer race; locked requests now respond with HTTP `423 Locked`
- centralized continue, resume, retry, and cancel HTTP handling on `Conductor::continueRun/resumeRun/retryRun/cancelRun`, rebuilt `Conductor::continueRun` and `RunProcessor::continueRun` to return a `WorkflowRunResultData` assembled inside the lock (fixing a stale-read window between the processor call and the post-lock state fetch), and replaced internal `RuntimeException` throws with typed exceptions in `Entrepeneur4lyf\LaravelConductor\Exceptions\*`
- refactored the `conductor:cancel` and `conductor:retry` Artisan commands to delegate to `Conductor::cancelRun` / `Conductor::retryRun`, so CLI mutations now go through the same `RunLockProvider` boundary and typed exceptions as the HTTP layer and events are dispatched exactly once per operation
- added `@method` docblocks to the `Conductor` facade so IDEs and static analysis see the full service surface, including the new `WorkflowRunResultData` return types from `continueRun` and `resumeRun`
- added a `conductor.locks` config block (`store`, `prefix`, `ttl`) and the `CONDUCTOR_LOCK_*` env keys; the default `CONDUCTOR_LOCK_TTL` is `60` seconds and is intentionally short so stuck processes recover quickly — see the layered concurrency model entry below for why the TTL is no longer load-bearing for correctness
- concurrent run-level writes are now protected by a three-layer defense: a short-TTL cache lock for cheap rejection, a revision re-check inside `RunProcessor::continueRun` immediately before the Atlas call (catches TTL-expired races before burning LLM tokens, surfaced as `RunRevisionMismatchException` → HTTP `409`), and optimistic concurrency on the final persist (via `OptimisticRunMutator`). Correctness is guaranteed at the write layer; the cache lock TTL is no longer load-bearing and defaults to 60 seconds.
- extracted the package from the original application into a Laravel 13 package
- added declarative YAML and JSON workflow loading with validation and frozen compiled snapshots
- added Atlas-backed structured step execution and Twig prompt rendering
- added a database-backed revisioned workflow dossier with optimistic concurrency
- added supervisor semantics for advance, retry, retry-with-prompt, skip, wait, fail, and complete
- added Atlas-backed escalation handling for unmatched failures and explicit `action: escalate` handlers
- added runtime `context_map` prompt resolution and lifecycle timeline entries
- added a working `Conductor` service surface, facade support, and a default null run lock provider
- replaced destructive step-run resync with stable in-place updates plus supporting indexes
- added lifecycle endpoints for start, continue, status, resume, retry, and cancel
- added artisan commands for workflow scaffolding, validation, status inspection, retry, and cancel
- added package stubs, events, migrations, and end-to-end test coverage
