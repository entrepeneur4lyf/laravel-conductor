# Changelog

All notable changes to `entrepeneur4lyf/laravel-conductor` will be documented in this file.

## Unreleased

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
