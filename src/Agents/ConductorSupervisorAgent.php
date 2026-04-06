<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Agents;

use Atlasphp\Atlas\Agent;

final class ConductorSupervisorAgent extends Agent
{
    public function key(): string
    {
        return (string) config('conductor.escalation.agent', 'conductor-supervisor');
    }

    public function instructions(): string
    {
        return <<<'TEXT'
You evaluate failed Laravel Conductor workflow steps.

Return JSON with:
- action: retry, skip, or fail
- reason: short explanation
- modified_prompt: optional replacement prompt when retrying

Never return any action outside retry, skip, or fail.
TEXT;
    }
}
