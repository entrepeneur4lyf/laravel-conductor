<?php

declare(strict_types=1);

use Atlasphp\Atlas\Agent;
use Atlasphp\Atlas\AgentRegistry;
use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Enums\Provider;
use Atlasphp\Atlas\Testing\TextResponseFake;
use Entrepeneur4lyf\LaravelConductor\Engine\EscalationEvaluator;

class ConductorSupervisorTestAgent extends Agent
{
    public function key(): string
    {
        return 'conductor-supervisor';
    }

    public function provider(): Provider|string|null
    {
        return Provider::OpenAI;
    }

    public function model(): ?string
    {
        return 'gpt-4o-mini';
    }
}

beforeEach(function (): void {
    app(AgentRegistry::class)->register(ConductorSupervisorTestAgent::class);
});

it('returns retry when the escalation agent recommends retry', function (): void {
    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'retry',
            'reason' => 'Prompt needs more detail.',
            'modified_prompt' => 'Retry with a more explicit rubric.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $decision = app(EscalationEvaluator::class)->evaluate(
        stepId: 'score',
        error: 'ambiguous_quality',
        stepOutput: ['score' => 3],
        originalPrompt: 'Score this content.',
        attempt: 1,
        maxRetries: 3,
    );

    expect($decision->action)->toBe('retry')
        ->and($decision->reason)->toContain('Prompt needs more detail')
        ->and($decision->modified_prompt)->toContain('explicit rubric');
});

it('returns skip when the escalation agent recommends skip', function (): void {
    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'skip',
            'reason' => 'This step is optional.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $decision = app(EscalationEvaluator::class)->evaluate(
        stepId: 'research',
        error: 'upstream_lookup_failed',
        stepOutput: [],
        originalPrompt: 'Research the topic.',
        attempt: 1,
        maxRetries: 2,
    );

    expect($decision->action)->toBe('skip')
        ->and($decision->reason)->toContain('optional');
});

it('returns fail when the escalation agent recommends fail', function (): void {
    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'fail',
            'reason' => 'The payload is unrecoverable.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $decision = app(EscalationEvaluator::class)->evaluate(
        stepId: 'write',
        error: 'irrecoverable_error',
        stepOutput: [],
        originalPrompt: 'Write the article.',
        attempt: 2,
        maxRetries: 3,
    );

    expect($decision->action)->toBe('fail')
        ->and($decision->reason)->toContain('unrecoverable');
});

it('degrades to fail when the escalation agent returns invalid json', function (): void {
    Atlas::fake([
        TextResponseFake::make()->withText('not valid json'),
    ]);

    $decision = app(EscalationEvaluator::class)->evaluate(
        stepId: 'score',
        error: 'weird_failure',
        stepOutput: [],
        originalPrompt: 'Score this content.',
        attempt: 1,
        maxRetries: 2,
    );

    expect($decision->action)->toBe('fail')
        ->and($decision->reason)->toContain('invalid JSON');
});

it('normalizes unsupported escalation actions to fail', function (): void {
    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'invent_new_action',
            'reason' => 'Something else.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $decision = app(EscalationEvaluator::class)->evaluate(
        stepId: 'score',
        error: 'unknown_error',
        stepOutput: [],
        originalPrompt: 'Score this content.',
        attempt: 1,
        maxRetries: 3,
    );

    expect($decision->action)->toBe('fail');
});

it('coerces escalated retry to fail after the retry budget is exhausted', function (): void {
    Atlas::fake([
        TextResponseFake::make()->withText(json_encode([
            'action' => 'retry',
            'reason' => 'Try again.',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $decision = app(EscalationEvaluator::class)->evaluate(
        stepId: 'score',
        error: 'retry_budget_exhausted',
        stepOutput: [],
        originalPrompt: 'Score this content.',
        attempt: 3,
        maxRetries: 3,
    );

    expect($decision->action)->toBe('fail')
        ->and($decision->reason)->toContain('max retries');
});
