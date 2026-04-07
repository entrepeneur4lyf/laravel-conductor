# HTTP and CLI API

This document describes the public API surface that exists in the code today.

Default route prefix:

```text
/api/conductor
```

That prefix is configurable through `config('conductor.routes.prefix')`.

## Shared Response Shapes

### Workflow Run

Most endpoints return a `data` object shaped like `WorkflowRunStateData`.

Important fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | string | Run identifier |
| `workflow` | string | Workflow name |
| `workflow_version` | int | Version from definition |
| `revision` | int | Monotonic optimistic concurrency counter |
| `status` | string | See run statuses below |
| `snapshot` | object | Frozen compiled workflow snapshot |
| `current_step_id` | string or null | Current step |
| `input` | object/array | Start payload |
| `output` | object/array or null | Final output when available |
| `context` | object/array | Stored context bag |
| `wait` | object or null | Wait state with `resume_token` |
| `retry_after` | string or null | ISO 8601 timestamp of the next earliest moment a failure-handler-driven retry will be honored. `null` outside of a backoff window. |
| `steps` | array | Step execution history |
| `timeline` | array | Timeline entries |

### Supervisor Decision

`/continue` and `/resume` return a `decision` object shaped like `SupervisorDecisionData`.

| Field | Type | Notes |
| --- | --- | --- |
| `action` | string | `advance`, `retry`, `retry_with_prompt`, `skip`, `wait`, `fail`, `complete`, `cancel`, or `noop` |
| `next_step_id` | string or null | Present when advancing |
| `reason` | string or null | Human-readable explanation |
| `modified_prompt` | string or null | Present for `retry_with_prompt` |
| `confidence` | float or null | DTO field exists, current runtime does not set it |
| `delay` | int or null | Present on retry decisions when a handler delay exists |

## Run Statuses

The current code uses these run statuses:

- `initializing`
- `running`
- `waiting`
- `completed`
- `failed`
- `cancelled`

## Step Statuses

The current code uses these step statuses:

- `pending`
- `running`
- `completed`
- `failed`
- `skipped`
- `retrying`

## HTTP Endpoints

### `POST /start`

Initialize a new workflow run.

Request validation:

- `workflow`: required string
- `input`: optional array

Example request:

```json
{
  "workflow": "content-pipeline",
  "input": {
    "topic": "Laravel Conductor"
  }
}
```

Behavior from code:

- loads the named workflow definition
- validates and compiles it
- stores a run with `revision = 1`
- sets the first step as `current_step_id`
- marks the run as `initializing`
- does not execute the step yet

Success response:

- status `201`
- body: `{ "data": { ...run... } }`

Failure modes:

- `422` if request validation fails
- `500` if workflow loading or compilation throws and is not handled by the framework layer

### `POST /runs/{runId}/continue`

Execute or evaluate the current run.

Request body:

- none

Behavior from code:

1. load the run
2. if `retry_after` is set and is in the future, return a `noop` decision with reason `Run is in retry backoff until {iso8601}.` — no executor call is made
3. if terminal, return `noop`
4. ask the supervisor whether the current state already implies a deterministic decision
4. if not, build `StepInputData`
5. persist the step as `running`
6. execute the step through the bound `WorkflowStepExecutor`
7. persist the step result as `completed` or `failed`
8. re-enter the supervisor and return the resulting decision

Current executor behavior:

- Atlas agent key comes from the step `agent`
- rendered prompt comes from the frozen prompt template contents
- `output_schema_path` is passed from the compiled snapshot
- structured output is used when a schema path exists
- `context_map` aliases are resolved before the prompt is rendered
- timeline entries are appended as the run changes state

Success response:

- status `200`
- body: `{ "data": { ...run... }, "decision": { ...decision... } }`

Not found:

- `404` with `{ "message": "Workflow run not found." }`

Conflict:

- `409` with `{ "message": "Run state advanced while your request was processing. Reload and retry." }`
  if the run revision moved underneath the current request between the lock
  acquire and the executor call (the layered concurrency model's pre-Atlas
  re-check). Reload the run dossier and retry against the new revision.

Locked:

- `423` with `{ "message": "Run is currently locked by another request." }`
  if a concurrent request is already mutating the same run.

### `GET /runs/{runId}`

Fetch the current stored run dossier.

Success response:

- status `200`
- body: `{ "data": { ...run... } }`

Not found:

- `404` with `{ "message": "Workflow run not found." }`

### `POST /runs/{runId}/resume`

Resume a waiting run with external payload.

Request validation:

- `resume_token`: required string
- `payload`: optional array

Example request:

```json
{
  "resume_token": "8d8f0f42-6f7f-4f1a-8a8b-7e9d0bb8dc3e",
  "payload": {
    "approved": true
  }
}
```

Behavior from code:

- run must be in `waiting`
- `resume_token` must match the stored wait state
- the current step is marked `completed`
- the provided `payload` is stored as that step's output payload
- wait state is cleared
- supervisor evaluation runs immediately after the step is completed

Success response:

- status `200`
- body: `{ "data": { ...run... }, "decision": { ...decision... } }`

Failure modes:

- `404` if run not found
- `404` if current step cannot be found
- `409` with `{ "message": "Run is not waiting." }`
- `422` with `{ "message": "Invalid resume token." }`
- `422` if request validation fails
- `423` with `{ "message": "Run is currently locked by another request." }`
  if a concurrent request is already mutating the same run.

### `POST /runs/{runId}/retry`

Append a new pending attempt to a failed run.

Request validation:

- `revision`: required integer, minimum `1`

Example request:

```json
{
  "revision": 3
}
```

Behavior from code:

- run must exist
- supplied revision must equal stored revision
- run must be `failed`
- `current_step_id` must be present
- a new pending step attempt is appended with `attempt + 1`
- run status is changed back to `running`
- the retry is not executed automatically; call `/continue` to run the appended attempt

Success response:

- status `200`
- body: `{ "data": { ...run... } }`

Failure modes:

- `404` if run not found
- `404` if current step cannot be found
- `409` with `{ "message": "Run revision mismatch." }`
- `422` with `{ "message": "Run is not eligible for retry." }`
- `422` if request validation fails
- `423` with `{ "message": "Run is currently locked by another request." }`
  if a concurrent request is already mutating the same run.

### `POST /runs/{runId}/cancel`

Cancel an active run.

Request validation:

- `revision`: required integer, minimum `1`

Behavior from code:

- run must exist
- supplied revision must equal stored revision
- completed, failed, and cancelled runs cannot be cancelled again
- successful cancellation clears `current_step_id` and `wait`

## Escalation Semantics

When a step fails:

- matching failure handlers still run first
- if no handler matches and the step still has retry budget remaining, Conductor escalates through the configured Atlas agent key in `conductor.escalation.agent`
- if a handler uses `action: escalate`, Conductor also escalates through that same Atlas agent
- valid AI dispositions are `retry`, `skip`, or `fail`
- invalid AI responses degrade to `fail`

Success response:

- status `200`
- body: `{ "data": { ...run... } }`

Failure modes:

- `404` if run not found
- `409` with `{ "message": "Run revision mismatch." }`
- `422` with `{ "message": "Run is not eligible for cancellation." }`
- `422` if request validation fails
- `423` with `{ "message": "Run is currently locked by another request." }`
  if a concurrent request is already mutating the same run.

## CLI Commands

These commands are currently registered by the service provider.

### `php artisan conductor:make-workflow {name} {--force}`

Scaffold a workflow file, prompt, and schema into the first configured definition path.

Current files created:

- `{root}/{name}.yaml`
- `{root}/prompts/research.md.j2`
- `{root}/schemas/research-output.json`

Notes from code:

- if any target file already exists, the command fails unless `--force` is passed
- the scaffold always uses the `research` step naming shown above

### `php artisan conductor:validate {workflow}`

Load, validate, and compile a workflow definition.

Argument behavior:

- accepts a workflow name, relative path, or absolute path

Success output:

- `Workflow [{name}] is valid.`
- `Compiled {stepCount} step(s) and {handlerCount} failure handler(s).`

### `php artisan conductor:status {runId}`

Show the current stored run dossier summary.

The command currently prints:

- run id
- workflow
- status
- revision
- current step
- wait type
- resume token

### `php artisan conductor:retry {runId} {--revision=}`

Retry a failed run from the console.

Behavior mirrors the HTTP retry endpoint:

- defaults expected revision to the run's current revision if omitted
- fails if the run is not retryable

### `php artisan conductor:cancel {runId} {--revision=}`

Cancel an active run from the console.

Behavior mirrors the HTTP cancel endpoint:

- defaults expected revision to the run's current revision if omitted
- fails if the run is already terminal

## Operational Notes

These are important current-package facts that come directly from the code:

- `/start` only initializes a run
- `/continue` is the endpoint that actually executes a pending step
- the current execution path is synchronous within the request
- retries are appended to run state synchronously (no background queue), but a failure handler `delay` is enforced via a persisted `retry_after` window on the run dossier — `/continue` returns a `noop` decision while the backoff is active
- `state.driver` is configurable in config but only the database state store is bound today
- every mutating run operation (`/continue`, `/resume`, `/retry`, `/cancel`) is wrapped in a `Cache::lock` keyed by the run id via `RunLockProvider`. The lock store, prefix, and TTL are configurable under `conductor.locks`. Requests that cannot acquire the lock get HTTP `423`.
- run-level concurrency uses a three-layer defense: (1) the cache lock above, which is a cheap first-line rejection and is not load-bearing for correctness; (2) a pre-Atlas revision re-check inside `RunProcessor::continueRun` that catches TTL-expired races before any LLM tokens are spent and surfaces as HTTP `409`; (3) `OptimisticRunMutator`'s `WHERE revision = ?` predicate at the final write, which is the authoritative correctness gate. The default `CONDUCTOR_LOCK_TTL` is intentionally short (60 seconds) so stuck processes recover quickly — do not raise it to "exceed your slowest step", because the TTL is not what prevents concurrent Atlas calls.
