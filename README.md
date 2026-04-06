# Laravel Conductor

`entrepeneur4lyf/laravel-conductor` is an Atlas-native workflow orchestration package for Laravel 13.

It is built for declarative multi-step workflows where:

- workflow definitions live in YAML or JSON
- prompts live in Twig templates
- step outputs are validated with JSON Schema
- Atlas agents execute the individual steps
- Conductor owns run state, revisions, transitions, and lifecycle endpoints

The current package surface is real and working. It is not a no-op skeleton anymore.

## What It Does

Today, the package can:

- load workflow definitions from YAML and JSON
- validate workflow structure before execution
- resolve prompt templates and schema files inside a workflow tree
- freeze those assets into a compiled snapshot stored on each run
- persist a database-backed workflow dossier with optimistic concurrency
- execute the current pending step through Atlas structured output
- resolve `context_map` values into prompt variables at runtime
- escalate unmatched failures or explicit `action: escalate` handlers through an Atlas supervisor agent
- advance, retry, skip, wait, resume, fail, cancel, and complete runs through the HTTP lifecycle API
- scaffold, validate, inspect, retry, and cancel runs through artisan commands

## What It Does Not Yet Do Automatically

Important so nobody gets surprised:

- it does not auto-dispatch the first step when you call `start`
- it does not currently queue step execution in the background
- it does not currently implement parallel fan-out execution from `parallel` and `foreach`
- it does not currently execute `tools` or `provider_tools`
- it does not currently consume `on_fail` transition semantics

Those fields exist in the definition model, but the runtime has a narrower active surface right now.

## Requirements

- PHP `^8.3`
- Laravel `^13.0`
- [atlas-php/atlas](https://github.com/atlasphp/atlas)

## Installation

```bash
composer require entrepeneur4lyf/laravel-conductor
php artisan vendor:publish --provider="Entrepeneur4lyf\\LaravelConductor\\LaravelConductorServiceProvider"
php artisan migrate
```

By default, the package:

- looks for workflow definitions in `workflows/`
- exposes routes under `/api/conductor`
- uses the database-backed workflow state store
- registers the `conductor-supervisor` Atlas agent for escalation decisions

## Quick Start

Create a workflow set:

```bash
php artisan conductor:make-workflow content-pipeline
```

Validate it:

```bash
php artisan conductor:validate content-pipeline
```

Start a run:

```bash
curl -X POST http://localhost/api/conductor/start \
  -H "Content-Type: application/json" \
  -d '{
    "workflow": "content-pipeline",
    "input": {
      "topic": "Laravel Conductor"
    }
  }'
```

Continue the current pending step:

```bash
curl -X POST http://localhost/api/conductor/runs/{runId}/continue
```

Retrying a failed run is explicit:

```bash
curl -X POST http://localhost/api/conductor/runs/{runId}/retry \
  -H "Content-Type: application/json" \
  -d '{
    "revision": 3
  }'
```

That appends a new pending attempt but does not execute it. Call `/continue` afterward to run the retry.

If the run enters `waiting`, resume it with the stored `resume_token`:

```bash
curl -X POST http://localhost/api/conductor/runs/{runId}/resume \
  -H "Content-Type: application/json" \
  -d '{
    "resume_token": "the-token-from-the-run",
    "payload": {
      "approved": true
    }
  }'
```

Inspect the current dossier:

```bash
curl http://localhost/api/conductor/runs/{runId}
```

## Example Workflow

```yaml
name: content-pipeline
version: 1
description: Content pipeline
defaults:
  timeout: 120

steps:
  - id: research
    agent: research-agent
    prompt_template: prompts/research.md.j2
    output_schema: '@schemas/research-output.json'
    retries: 2
    on_success: approval

  - id: approval
    agent: approval-agent
    prompt_template: prompts/approval.md.j2
    output_schema: '@schemas/approval-output.json'
    wait_for: approval
    retries: 0
    on_success: complete

failure_handlers:
  - match: schema_validation_failed
    action: retry
    delay: 30
    prompt_template: prompts/research.md.j2
```

Example workflow set layout:

```text
workflows/
  content-pipeline.yaml
  prompts/
    research.md.j2
    approval.md.j2
  schemas/
    research-output.json
    approval-output.json
```

## Lifecycle Model

Current package behavior:

1. `POST /api/conductor/start` creates a run and stores revision `1`.
2. The run is stored with a compiled snapshot and the first `current_step_id`.
3. `POST /api/conductor/runs/{runId}/continue` executes the current pending step through Atlas.
4. Supervisor evaluation decides whether to advance, retry, wait, fail, cancel, complete, or noop.
5. If the run is `waiting`, `POST /api/conductor/runs/{runId}/resume` completes that waiting step with external payload and re-enters supervisor evaluation.
6. Failed steps with remaining retry budget can escalate through the registered Atlas supervisor agent when no handler matches or when a handler uses `action: escalate`.

The package is currently explicit and API-driven. Starting a run does not auto-execute the first step.

## HTTP API

Default route prefix:

```text
/api/conductor
```

Routes currently shipped:

- `POST /start`
- `POST /runs/{runId}/continue`
- `GET /runs/{runId}`
- `POST /runs/{runId}/resume`
- `POST /runs/{runId}/retry`
- `POST /runs/{runId}/cancel`

Deep API docs:

- [HTTP and CLI API](./docs/package/API.md)

## Artisan Commands

Commands currently shipped:

- `php artisan conductor:make-workflow {name}`
- `php artisan conductor:validate {workflow}`
- `php artisan conductor:status {runId}`
- `php artisan conductor:retry {runId} --revision=`
- `php artisan conductor:cancel {runId} --revision=`

## Documentation

- [Getting Started](./docs/package/GETTING_STARTED.md)
- [Workflow Sets](./docs/package/WORKFLOW_SETS.md)
- [HTTP and CLI API](./docs/package/API.md)

## Testing

```bash
composer test
```

Current green verification in this repo:

- `rtk vendor/bin/pest`
