# Laravel Conductor — Runtime Semantics

**Date:** 2026-04-06
**Status:** Authoritative — governs package implementation
**Scope:** Exact transition rules, operation sequences, concurrency model, idempotency guarantees, and error handling for the Conductor workflow engine.

This document governs runtime behavior. Read it alongside
`2026-04-06-laravel-conductor-design.md`. Where they diverge, this
document wins.

## 1. Foundational Rules

These rules are inviolable.

**R1. State before dispatch.**
A run dossier must be persisted with the new `current_step_id` and
incremented `revision` before any job is dispatched for that step.

**R2. Revision is monotonic.**
Every dossier write increments `revision` by exactly 1. Writes against
stale revisions must be rejected, not silently applied.

**R3. Deterministic before intelligent.**
Evaluation order is always:
1. Schema validation
2. Quality rules
3. Failure handler matching
4. AI escalation

AI escalation is only allowed when no deterministic path exists.

**R4. Terminal runs are immutable.**
Once a run is `completed`, `failed`, or `cancelled`, no further
mutations are allowed except explicit operator actions like replay.

**R5. Workers are stateless.**
Workers receive `StepInputData` and return `StepOutputData`. They do not
read or write workflow state directly.

## 2. State Machines

### 2.1 Run States

- `pending`
- `initializing`
- `running`
- `waiting`
- `completed`
- `failed`
- `cancelled`

### 2.2 Step States

- `pending`
- `running`
- `completed`
- `failed`
- `skipped`
- `retrying`

## 3. Revision and Concurrency

### 3.1 Revision field

Every workflow dossier carries an integer `revision` that starts at `1`
on initialization and increments by exactly `1` on every successful
write.

### 3.2 Optimistic write protocol

Every dossier write must follow this sequence:

1. Read current dossier, note revision `N`
2. Apply mutations in memory
3. Attempt write with condition `revision == N`
4. If write succeeds, revision becomes `N + 1`
5. If write fails, reject the mutation and log a conflict

The failing caller must not re-read and re-apply automatically.

### 3.3 What increments revision

- supervisor advances to a new step
- supervisor assigns any disposition
- step transitions `pending -> running`
- entering or leaving `waiting`
- operator actions such as retry or cancel

### 3.4 Default locking strategy

V2 default is database optimistic concurrency using a `revision` column.
Distributed locking is optional behind a `RunLockProvider` contract.

## 4. Disposition Rules

Supported dispositions:

- `advance`
- `retry`
- `retry_with_prompt`
- `skip`
- `wait`
- `escalate`
- `fail`
- `complete`
- `cancel`

### 4.1 `advance`

Pre-conditions:
- current step status is `completed`
- schema validation passed if schema exists
- quality rules passed if defined
- `on_success` resolves to a valid step or terminal value

Operation sequence:
1. mark current `StepRun` with `supervisor_decision = advance`
2. resolve `on_success`
3. if `complete`, apply `complete`
4. if `discard`, apply `cancel`
5. create new `StepRun` for the next step with `status = pending`
6. update run `current_step_id`
7. increment revision and persist atomically
8. emit disposition event
9. dispatch `ExecuteStepJob`

### 4.2 `retry`

Pre-conditions:
- step failed or validation failed
- `current_attempt < step.retries`
- failure handler matched `retry`

Operation sequence:
1. mark current `StepRun` as `retrying`
2. record retry reason
3. create new `StepRun` with incremented attempt and `status = pending`
4. increment revision and persist atomically
5. emit retry event
6. dispatch `ExecuteStepJob`, delayed if required

If retry budget is exhausted, fall through to `fail`.

### 4.3 `retry_with_prompt`

Same as `retry`, plus:
- render handler prompt template with error context
- store the rendered override on the new `StepRun`
- `ExecuteStepJob` must prefer `prompt_override` when present

### 4.4 `skip`

Pre-conditions:
- condition evaluated false
- or failure handler matched `skip`
- or AI escalation returned `skip`

Operation sequence:
1. mark current `StepRun` as `skipped`
2. record skip reason
3. increment revision and persist atomically
4. emit skip event
5. continue using skipped step's `on_success`

Condition checks happen before worker execution.

### 4.5 `wait`

Pre-conditions:
- failure handler uses `wait`
- or step declares `wait_for`

Operation sequence:
1. mark current `StepRun` with `supervisor_decision = wait`
2. update run status to `waiting`
3. record wait metadata such as `wait_type`, `timeout_at`, `resume_token`
4. increment revision and persist atomically
5. emit `RunWaiting`
6. do not dispatch a job

Resume flow:
1. verify run is still `waiting`
2. verify `resume_token` if applicable
3. apply external result to the waiting step
4. dispatch evaluation

### 4.6 `escalate`

Pre-conditions:
- no failure handler matched or matched `escalate`
- retries remain

Operation sequence:
1. render escalation prompt
2. invoke supervisor-grade Atlas agent
3. parse structured response `{ action, reason, modified_prompt?, confidence }`
4. validate action is one of `retry`, `skip`, `fail`
5. if retry budget is exhausted, override retry to fail
6. apply resulting disposition using the same rules as deterministic paths
7. record `disposition_source = ai_escalation`

Escalation failures do not retry the escalation; they fail the run.

### 4.7 `fail`

Pre-conditions:
- retry budget exhausted
- or failure handler matched `fail`
- or AI escalation returned `fail`
- or unrecoverable engine error

Operation sequence:
1. mark current `StepRun` failed
2. record failure reason
3. update run to `failed`
4. increment revision and persist atomically
5. emit `RunFailed`
6. do not dispatch further jobs

### 4.8 `complete`

Pre-conditions:
- final step resolves to `complete`
- all evaluations passed

Operation sequence:
1. mark final `StepRun` with `supervisor_decision = advance`
2. update run to `completed`, set `output`, clear `current_step_id`, set `completed_at`
3. increment revision and persist atomically
4. emit `RunCompleted`
5. do not dispatch further jobs

### 4.9 `cancel`

Triggered by operator action, `discard`, or system decision.

Operation sequence:
1. verify run is not terminal
2. verify revision matches
3. update run to `cancelled`
4. increment revision and persist atomically
5. emit `RunCancelled`

In-flight jobs must become no-ops when they notice the terminal run.

## 5. Idempotency and Stale Event Handling

### 5.1 Duplicate evaluation

`EvaluateStepJob` must no-op when:
- the run is terminal
- `current_step_id` no longer matches the evaluated step
- `supervisor_decision` is already set on that `StepRun`

### 5.2 Stale retries

Delayed retries that arrive after success must no-op. Job lookup must
match step id and attempt number, and only run when the targeted
`StepRun` is still `pending`.

### 5.3 Continuation against terminal runs

Any execute/evaluate/resume continuation against `completed`, `failed`,
or `cancelled` runs must return a harmless no-op and log debug context.

### 5.4 Operator retry

Manual retry requires:
1. run status is `failed`
2. revision matches expected revision
3. new `StepRun` is created with incremented attempt
4. run transitions back to `running`
5. revision increments atomically before dispatch

On revision mismatch, return `409 Conflict`.

## 6. Evaluation Order

Supervisor evaluation order is exact:

1. load run safely
2. load step definition from compiled snapshot
3. load latest relevant `StepRun`
4. guard checks for terminal or stale situations
5. handle `skipped` status
6. handle `failed` status
7. schema validation
8. quality rules
9. advance if all pass
10. failure path:
   - handler match
   - retry / retry_with_prompt / skip / fail / escalate
   - only escalate when no deterministic handler path exists

## 7. Compiled Snapshot

### 7.1 What it is

At initialization, Conductor compiles the authored YAML/JSON definition
into a frozen execution snapshot stored with the run dossier.

The snapshot includes:
- workflow name and version
- full resolved step graph
- resolved prompt template paths
- resolved schema paths
- failure handlers
- defaults
- `compiled_at`
- `source_hash`

### 7.2 Why it exists

In-flight runs must not silently change behavior when someone edits the
source YAML during execution.

### 7.3 What it does not include

- rendered prompt text

Prompt templates render fresh from their frozen path. Schema paths must
remain valid for the run lifetime.

## 8. Parallel Execution Semantics

Parallel steps are experimental in V2.

### 8.1 Fan-out

When entering a `parallel: true` step with `foreach`:
1. resolve the collection from prior output
2. if empty, skip the step and advance
3. create all `StepRun` records first
4. increment revision once for all creations and state changes
5. dispatch all step jobs through `Bus::batch()`

### 8.2 Fan-in

When the batch finishes:
1. collect all step runs ordered by `batch_index`
2. if any failed, evaluate failure against the first failed item
3. if all completed, merge ordered outputs
4. validate merged results according to configured policy
5. apply advance or failure disposition

### 8.3 Partial failure policy

V2 supports:
- `on_partial_failure: fail` (default)
- `on_partial_failure: continue`

### 8.4 Retry semantics

Parallel retries are whole-step retries in V2.

## 9. Atlas Structured Output

The default Atlas worker path must use structured output when a schema
is available.

`ExecuteStepJob` injects `output_schema_path` into
`StepInputData::$meta`, resolving `@schemas/` to an absolute path.

Workers should prefer:
- `withSchema(...)->asStructured()` when schema exists
- `asText()` only as a fallback when no schema exists

## 10. V2 Decisions

- Default dossier store: database-backed JSON on `pipeline_runs`
- Default concurrency: optimistic DB revision checks
- Locking: optional `RunLockProvider`
- Prompt rendering: `PromptRenderer` contract with Twig default
- Wait states: included in V2 with `resume_token` UUID validation
- Field naming: prefer `context_map` over `context_mapping`
