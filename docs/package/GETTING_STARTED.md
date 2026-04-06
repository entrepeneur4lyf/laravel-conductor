# Getting Started

This package is currently an Atlas-native, HTTP-driven workflow orchestrator for Laravel 13.

What the code does today:

- loads workflow definitions from YAML or JSON
- validates prompts and schemas
- compiles a frozen workflow snapshot into the stored run dossier
- persists run state in the database with optimistic concurrency
- executes the current step through Atlas when you call the `/continue` endpoint
- resolves `context_map` aliases into Twig prompt variables
- escalates unmatched failures through a registered Atlas supervisor agent
- evaluates supervisor decisions after execution

What it does not do automatically today:

- queue or schedule retries for you
- dispatch background jobs for step execution
- switch persistence drivers away from the database store that is currently bound in the service provider
- execute `tools`, `provider_tools`, or `on_fail` transitions automatically

## Requirements

- PHP `^8.3`
- Laravel `^13.0`
- `atlas-php/atlas`

## Installation

```bash
composer require entrepeneur4lyf/laravel-conductor
php artisan vendor:publish --provider="Entrepeneur4lyf\\LaravelConductor\\LaravelConductorServiceProvider"
php artisan migrate
```

## Published Configuration

The package publishes `config/conductor.php` with these keys:

```php
return [
    'definitions' => [
        'paths' => [
            env('CONDUCTOR_DEFINITIONS_PATH', base_path('workflows')),
        ],
    ],
    'state' => [
        'driver' => env('CONDUCTOR_STATE_DRIVER', 'database'),
    ],
    'escalation' => [
        'agent' => env('CONDUCTOR_ESCALATION_AGENT', 'conductor-supervisor'),
    ],
    'routes' => [
        'prefix' => env('CONDUCTOR_ROUTE_PREFIX', 'api/conductor'),
        'middleware' => [
            'api',
        ],
    ],
];
```

Notes based on current code:

- `definitions.paths` is used by the definition repository when you pass a bare workflow name like `content-pipeline`.
- `routes.prefix` and `routes.middleware` are active.
- `state.driver` exists in config, but the current package binding is the database-backed store in `LaravelConductorServiceProvider`.
- `escalation.agent` controls which Atlas agent key Conductor uses for AI escalation decisions.

## First Workflow

Scaffold a workflow set into the first configured definitions path:

```bash
php artisan conductor:make-workflow content-pipeline
```

That command creates:

- `workflows/content-pipeline.yaml`
- `workflows/prompts/research.md.j2`
- `workflows/schemas/research-output.json`

Validate it:

```bash
php artisan conductor:validate content-pipeline
```

## Typical Lifecycle

The current package surface is explicit and API-driven:

1. Create a workflow definition set under `workflows/`.
2. Start a run with `POST /api/conductor/start`.
3. Call `POST /api/conductor/runs/{runId}/continue` to execute the current pending step.
4. If the run enters `waiting`, call `POST /api/conductor/runs/{runId}/resume` with the `resume_token`.
5. Inspect run state with `GET /api/conductor/runs/{runId}` or `php artisan conductor:status {runId}`.

`/start` initializes the run and stores revision `1`. It does not execute the first step by itself.

If a run is `failed`, `POST /api/conductor/runs/{runId}/retry` appends a new pending attempt, but it still requires a follow-up `POST /api/conductor/runs/{runId}/continue` to execute that retry.

## Documentation Map

- [Workflow Sets](./WORKFLOW_SETS.md)
- [HTTP and CLI API](./API.md)
