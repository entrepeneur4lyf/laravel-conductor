# Changelog

All notable changes to `entrepeneur4lyf/laravel-conductor` will be documented in this file.

## Unreleased

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
