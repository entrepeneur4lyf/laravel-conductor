# Workflow Sets

A workflow set is the directory structure Conductor reads when it compiles a workflow definition.

The current code expects a workflow tree like this:

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

You can also author the definition as JSON:

```text
workflows/
  content-pipeline.json
```

## How Resolution Works

The definition repository resolves workflows like this:

- bare workflow name, for example `content-pipeline`
- explicit relative path, for example `workflows/content-pipeline.yaml`
- explicit absolute path

If you pass a bare workflow name, the repository searches the configured `conductor.definitions.paths` and tries these extensions:

- `.yaml`
- `.yml`
- `.json`

## Example Definition

This example matches the current package surface:

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

## Top-Level Fields

These fields are loaded into `WorkflowDefinitionData` today:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `name` | string | yes | Workflow name stored on the run |
| `version` | int | yes | Workflow version stored on the run |
| `steps` | array | yes | Must contain at least one step |
| `description` | string | no | Preserved on the compiled snapshot |
| `failure_handlers` | array | no | Defaults to `[]` |
| `defaults` | object/array | no | Merged into each step at load time. See the "Defaults" section below. |

## Defaults

The workflow root may declare a `defaults` block. At load time, each
key in the defaults block is propagated into every step that does NOT
explicitly declare the same field. This lets you avoid repeating
common values like `retries` or `timeout` across many steps.

Merge-eligible keys (everything else is rejected):

- `retries`
- `timeout`
- `tools`
- `provider_tools`
- `meta`

Semantics:

- A step value always wins over a defaults value (`array_key_exists`
  on the raw payload, so explicit `0` or `[]` are preserved).
- Arrays are **replaced**, not deep-merged. If a step declares
  `tools: [foo]` and defaults declares `tools: [bar, baz]`, the
  resulting step has `tools: [foo]` only.
- Step-identity fields (`id`, `agent`, `prompt_template`,
  `output_schema`) are rejected from the defaults block at load
  time. Putting them there raises an `InvalidArgumentException`.

```yaml
name: example
defaults:
  retries: 3
  timeout: 90
  tools:
    - shared_tool
steps:
  - id: research
    agent: research-agent
    # inherits retries: 3, timeout: 90, tools: [shared_tool]
  - id: review
    agent: review-agent
    retries: 0          # explicit — overrides default
    tools:
      - reviewer_tool   # replaces the defaults list entirely
```

## Step Fields

These step fields are accepted by the loader and compiler:

| Field | Type | Required | Current runtime behavior |
| --- | --- | --- | --- |
| `id` | string | yes | Used as the execution and transition key |
| `agent` | string | yes | Passed to the Atlas executor |
| `prompt_template` | string | no | Resolved, validated, frozen into the compiled snapshot |
| `output_schema` | string | no | Resolved, validated, frozen into the compiled snapshot |
| `wait_for` | string | no | Causes a pending step to enter `waiting` when evaluated |
| `context_map` | object/array | no | Resolved against `input`, `context`, `output`, `workflow`, and `step` and exposed as prompt variables |
| `parallel` | bool | no | Validated, but not executed as parallel fan-out today |
| `foreach` | string | no | Required when `parallel: true`, but not executed today |
| `retries` | int | no | Used by retry budget logic |
| `timeout` | int | no | Validated and preserved, not currently enforced as execution timeout |
| `on_success` | string | no | Used by supervisor transitions |
| `on_fail` | string | no | Validated and preserved, not consumed by runtime transitions today |
| `condition` | string | no | Used by deterministic skip evaluation |
| `quality_rules` | array | no | Used after schema validation |
| `tools` | array | no | Resolved at execution time via `ToolResolver` and passed to Atlas via `withTools()`. See the "Tools" section in `README.md` for the three resolution strategies. |
| `provider_tools` | array | no | Resolved at execution time via `ProviderToolResolver` and passed to Atlas via `withProviderTools()`. Accepts bare strings (`web_search`) or objects with `type` and options. |
| `meta` | array | no | Forwarded into step metadata for the executor |

## Failure Handler Fields

These fields are accepted by the loader and compiler:

| Field | Type | Required | Current runtime behavior |
| --- | --- | --- | --- |
| `match` | string | yes | Treated as a case-insensitive regular expression |
| `action` | string | yes | See supported actions below |
| `delay` | int | no | Enforced on `retry` actions via a persisted `retry_after` timestamp on the run dossier — subsequent `/continue` calls return a `noop` decision until the backoff elapses. Also used for `wait` timeout calculation. |
| `prompt_template` | string | no | Required for `retry_with_prompt` |

## Supported Validation Rules

The definition validator enforces these rules today:

- at least one step must exist
- step ids must be unique
- `retries` must be `>= 0`
- `timeout` must be `> 0`
- `parallel: true` requires `foreach`
- prompt templates must resolve inside the workflow tree
- schemas must resolve inside the workflow tree
- transition targets must point to a known step or one of the supported terminal targets
- failure handler delays must be `>= 0`
- `retry_with_prompt` requires `prompt_template`

### Supported Terminal Targets

- `complete`
- `discard`
- `fail`
- `cancel`

### Supported Failure Handler Actions at Validation Time

- `retry`
- `retry_with_prompt`
- `skip`
- `wait`
- `escalate`
- `fail`

Important runtime note:

- `retry`, `retry_with_prompt`, `skip`, `wait`, `escalate`, and `fail` have explicit runtime handling.
- `escalate` uses the configured Atlas supervisor agent and accepts only `retry`, `skip`, or `fail` as valid AI dispositions.

## Prompt Templates

Prompt templates are rendered with Twig.

Current constraints enforced by `TemplateRenderer`:

- templates must resolve within the workflow tree
- standalone templates are supported
- Twig `include`, `extends`, `embed`, `use`, `import`, `from`, and `source()` style references are rejected

That means a simple prompt file like this is fine:

```twig
Research the topic and return a structured summary.
```

## JSON Schemas

Schemas are validated with `justinrainbow/json-schema`.

Current constraints enforced by `SchemaValidator`:

- schema files must decode to a JSON object
- external `$ref` values are rejected
- local fragment refs like `#/properties/foo` are allowed
- schema files must resolve within the workflow tree

The package uses the resolved schema path during execution, and also stores the frozen schema contents in the compiled snapshot.

## Compiled Snapshot Behavior

When you validate and compile a workflow, the package stores:

- the original authored references, for example `prompts/research.md.j2`
- the resolved absolute paths
- the full prompt contents
- the full schema contents
- a `source_hash`
- `compiled_at`

That compiled snapshot is stored on the workflow run so execution can use frozen assets instead of re-reading mutable source files.

## What a Scaffolded Workflow Set Looks Like

`php artisan conductor:make-workflow content-pipeline` creates:

- `content-pipeline.yaml`
- `prompts/research.md.j2`
- `schemas/research-output.json`

The scaffold currently includes:

- one `research` step
- a `context_map` example
- a `wait_for` example
- a `schema_validation_failed` retry handler

That scaffold is a starting point, not a statement that every field in it is fully executed by the runtime today.

## Recommended Authoring Pattern Today

Based on the current code, the safest fields to rely on in production are:

- `id`
- `agent`
- `prompt_template`
- `output_schema`
- `wait_for`
- `retries`
- `on_success`
- `condition`
- `quality_rules`
- `tools`
- `provider_tools`
- `failure_handlers` with `retry`, `retry_with_prompt`, `skip`, `wait`, or `fail` (with `delay` honored via persisted `retry_after` backoff)

Treat these as accepted but not fully active runtime features for now:

- `parallel` / `foreach` (fan-out execution is a future milestone)
- `on_fail` (not yet consumed as a transition target)
- step-level `timeout` (validated and inheritable from `defaults`, but not yet enforced at execution time)
