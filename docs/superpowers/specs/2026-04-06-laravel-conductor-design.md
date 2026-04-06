<!-- markdownlint-disable MD013 -->

# Laravel Conductor Design Spec

Date: 2026-04-06
Status: Revised draft for review
Package: `entrepeneur4lyf/laravel-conductor`
Target runtime: Laravel `^13.0`, PHP `^8.3`

This design spec is governed by the companion runtime semantics spec at
`docs/superpowers/specs/2026-04-06-laravel-conductor-runtime-semantics.md`.
If there is any conflict between the two, the runtime semantics spec wins.

## 1. Summary

Laravel Conductor is an Atlas-native workflow orchestration package for Laravel 13.

It executes declarative multi-step workflows authored in YAML or JSON, where each step invokes an Atlas agent with structured request and response contracts. Conductor owns workflow definition loading, validation, task-state persistence, supervisor evaluation, retry and disposition logic, and continuation across process boundaries.

Conductor does not depend on a long-running in-memory process. A workflow advances through explicit handoffs:

1. a trigger starts or resumes a workflow
2. the supervisor loads task state and selects the next step
3. an Atlas agent executes the step
4. the result is evaluated and a disposition is assigned
5. an endpoint or callback re-enters the supervisor for the next transition

Canonical workflow state is persisted as a revisioned workflow dossier. The
default V2 implementation stores that dossier in the database with optimistic
concurrency. File-backed state is a portability fallback, not the default.
Atlas persistence and events provide execution telemetry and integration hooks,
but the Conductor dossier is the authoritative workflow state.

## 2. Goals

- Allow workflows to be authored without PHP code through YAML or JSON definitions.
- Use Atlas as the required execution engine for agent steps, schemas, tools, middleware, queues, and events.
- Keep supervisor lifecycle stateless between invocations so PHP process lifetime is not a correctness dependency.
- Persist only relevant workflow context, decisions, outputs, and timeline in a structured task-state document.
- Support deterministic progression where possible and intelligent evaluation where necessary.
- Be compatible with a future no-code workflow authoring tool.

## 3. Non-Goals

- No required UI in the package.
- No mandatory Inertia, React, Livewire, or Filament dependency.
- No requirement that workflows be authored as PHP classes.
- No resident daemon or continuously running supervisor loop.
- No round-robin chat transcript as the canonical state format.

## 4. Core Concepts

### 4.1 Workflow Definition

A workflow definition is an authored YAML or JSON document that declares:

- workflow identity and metadata
- ordered or branched steps
- Atlas agent or worker bindings per step
- prompt template references
- structured output schema references
- retry, timeout, and disposition behavior
- terminal states and transitions

Definitions are consumed and compiled into typed internal data objects. The authored files remain the primary source format.

### 4.2 Task-State File

Each workflow run has a canonical workflow dossier. In V2, the default
implementation stores it in the database as structured JSON with a monotonic
`revision` field. This dossier contains only relevant workflow context:

- workflow metadata
- normalized input payload
- compiled workflow snapshot
- revision
- current status and current step
- prior step outputs
- decisions and dispositions
- retry history
- timeline entries
- next-action metadata

The dossier is a curated workflow state document, not a full chat transcript.

### 4.3 Supervisor

The supervisor owns the end-to-end workflow. It:

- loads the workflow definition
- loads the task-state file
- resolves the current step
- builds the Atlas request for that step
- evaluates returned output
- determines disposition
- persists updated state
- triggers the next transition

The supervisor is not a long-running process. It is re-entered through explicit lifecycle boundaries.

### 4.4 Step Agent

Each step invokes an Atlas agent with:

- a task-specific prompt
- step-scoped context
- configured tools or provider tools
- request metadata
- structured output schema

Each step agent has its own narrower workflow. It does not own the end-to-end orchestration graph.

### 4.5 Run and Step State Machines

Observable run states:

- `pending`
- `initializing`
- `running`
- `waiting`
- `completed`
- `failed`
- `cancelled`

Observable step states:

- `pending`
- `running`
- `completed`
- `failed`
- `skipped`
- `retrying`

## 5. Package Dependencies

### Required

- `atlas-php/atlas`
- `spatie/laravel-data`
- YAML parser support
- JSON schema validation support

### Optional / Deferred

- Atlas persistence enabled in the host application
- Redis or advisory locks through a pluggable `RunLockProvider`
- UI example applications

## 6. Definition Contract

The primary authored artifacts are:

1. workflow definition file in YAML or JSON
2. JSON Schema files for structured outputs
3. prompt templates for step execution and handler overrides

Example workflow fields include:

- `name`
- `version`
- `description`
- `steps`
- `failure_handlers`

Each step may define:

- `id`
- `agent`
- `prompt_template`
- `output_schema`
- `retries`
- `timeout`
- `parallel`
- `foreach`
- `condition`
- `wait_for`
- `on_success`
- `on_fail`
- `context_map`
- `quality_rules`

Failure handlers may define:

- `match`
- `action`
- `delay`
- `prompt_template`

## 7. Typed Internal Model

Conductor should convert authored definitions into typed `Laravel Data` DTOs.

Expected DTO families:

- `WorkflowDefinitionData`
- `StepDefinitionData`
- `FailureHandlerData`
- `CompiledWorkflowData`
- `WorkflowRunStateData`
- `StepExecutionStateData`
- `SupervisorDecisionData`
- `DispositionData`
- `TimelineEntryData`

Atlas schemas and `laravel-data` serve different roles:

- Atlas schema defines what an agent must return.
- Laravel Data defines how Conductor validates, transforms, persists, and transports workflow state and runtime payloads.

## 8. Runtime Architecture

### 8.1 Lifecycle

The canonical lifecycle is:

1. Trigger
   HTTP endpoint or tool call starts a workflow.
2. Initialize
   Conductor validates the definition, compiles a frozen workflow snapshot, creates the initial dossier with `revision = 1`, and records the initial timeline entry.
3. Supervise
   Supervisor resolves the next step and builds the Atlas request.
4. Execute
   Atlas executes the step agent with structured request and response contracts.
5. Evaluate
   Conductor evaluates the step result in exact deterministic order before any intelligent escalation.
6. Disposition
   Conductor assigns one of: `advance`, `retry`, `retry_with_prompt`, `skip`, `wait`, `escalate`, `fail`, `complete`, `cancel`.
7. Continue
   A callback or endpoint re-enters the supervisor to trigger the next step programmatically.

### 8.2 Execution Principle

Conductor must not rely on one long-running PHP process to hold orchestration state. Every transition must be safe across process restarts.

Two foundational rules govern every transition:

- state must be persisted before dispatch
- every successful state write must increment `revision` by exactly 1

### 8.3 Continuation Mechanism

Continuation is API-driven. The endpoint receives the disposition payload, rehydrates workflow state, determines the next action, persists updates using optimistic concurrency, and dispatches the next step only after the dossier write succeeds.

Queues may still be used as an execution detail, but correctness must not depend on a resident queue worker holding state in memory.

## 9. Evaluation Model

Supervisor evaluation is hybrid and ordered:

### Deterministic first

- guard checks for terminal or stale runs
- condition and skip checks
- schema validation
- quality rules
- failure handler matching
- retry budget checks
- transition resolution

### Intelligent when needed

When deterministic evaluation cannot confidently assign disposition and no deterministic handler path exists, Conductor may invoke a supervisor-grade Atlas agent to make a structured judgment.

This AI-powered evaluation must still emit a typed disposition payload and write the result into the task-state file.

## 10. State and Persistence

### Canonical

- revisioned workflow dossier per run
- database-backed dossier storage by default in V2
- compiled workflow snapshot frozen at initialization

### Supporting

- Laravel events emitted at lifecycle boundaries
- Atlas persistence records when enabled by the host app

### Future extension

- pluggable workflow state store contract
- optional file-backed store for local portability
- Redis locks or other lock providers through `RunLockProvider`
- alternative storage backends if needed

Atlas persistence is valuable for execution telemetry, token usage, tool calls, and debugging. It is not the authoritative Conductor workflow state.

## 11. Integration with Atlas

Conductor is a hard Atlas extension package, not an adapter-optional package.

Conductor should leverage:

- Atlas agents
- structured output via `withSchema(...)`
- request metadata via `withMeta(...)`
- tools and provider tools
- Atlas middleware, especially step-level middleware
- queued execution support
- Atlas lifecycle events
- Atlas persistence where enabled

Conductor should model each step as an Atlas agent invocation with a step-scoped prompt and schema, not as an unrelated worker runtime.

The default step executor should use Atlas structured output via
`withSchema(...)->asStructured()` whenever an output schema is present.

## 12. Validation Responsibilities

Before execution:

- parse YAML or JSON
- normalize the definition
- compile a frozen workflow snapshot
- validate required fields
- validate step uniqueness
- validate transition targets
- validate prompt template references
- validate schema references
- validate handler references
- validate `parallel` and `foreach` semantics

During execution:

- validate structured output against the declared schema
- validate deterministic quality rules
- validate disposition shape before continuation
- reject stale writes using revision checks

## 13. Public Surface for V1

### Publishable assets

- package config
- migrations
- prompt template stubs
- schema stubs
- workflow definition stub

### Endpoints

V1 should include HTTP endpoints for:

- start workflow
- continue workflow
- resume waiting workflow
- receive step disposition callback
- get workflow status
- retry workflow or step
- cancel workflow

### Commands

V1 should include Artisan commands for:

- validating workflow definitions
- scaffolding a workflow file
- scaffolding schema and prompt assets
- inspecting workflow status
- replaying or retrying runs

## 14. Package Structure

Proposed package structure:

```text
src/
  Commands/
  Contracts/
  Data/
  Engine/
  Events/
  Exceptions/
  Http/
    Controllers/
    Requests/
  Persistence/
  Prompts/
  Schemas/
  Support/
  LaravelConductorServiceProvider.php

config/
  conductor.php

database/
  migrations/

routes/
  api.php

stubs/
  workflow.stub.yaml
  schema.stub.json
  prompt.stub.md.j2
```

## 15. V1 Scope

V1 should include:

- Laravel 13 package infrastructure
- Atlas dependency integration
- YAML/JSON workflow loader and validator
- typed `Laravel Data` runtime model
- revisioned dossier storage with DB optimistic concurrency
- compiled workflow snapshot
- stateless supervisor runtime
- structured Atlas step execution
- deterministic and intelligent disposition handling
- wait/resume semantics
- REST lifecycle endpoints
- event emission
- sample workflow artifacts
- end-to-end tests

## 16. Deferred Scope

Deferred until the backend contract is stable:

- bundled UI
- opinionated admin dashboard
- React, Livewire, or Filament example apps
- visual workflow editor integration
- additional state stores beyond the default DB dossier model

## 17. Acceptance Criteria

The first implementation should be considered successful when:

- a workflow can be authored fully in YAML or JSON without PHP step-definition code
- Conductor validates the workflow before execution
- a workflow run creates a revisioned canonical dossier
- every state mutation uses optimistic concurrency and increments revision exactly once
- compiled workflow snapshots isolate in-flight runs from later YAML edits
- each step executes through Atlas with structured output validation
- the supervisor can assign disposition after each step
- wait and resume flows are first-class and testable
- continuation works through explicit process boundaries
- retries and handler prompt overrides work
- workflow state is resumable after process restart
- package tests prove lifecycle correctness and failure recovery

## 18. Open Questions

- whether Atlas persistence should be strongly recommended or merely optional in the initial docs
- how much of the worker terminology from the bootstrap should be retained versus renamed to Atlas-native agent terminology
- whether V1 needs a Redis-backed accelerator immediately or can ship file-state only first
- whether prompt rendering should remain Twig-like from the bootstrap or align more tightly with the host app's preferred template strategy
