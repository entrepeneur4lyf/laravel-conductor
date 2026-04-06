<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Engine;

use Atlasphp\Atlas\Atlas;
use Entrepeneur4lyf\LaravelConductor\Data\SupervisorDecisionData;
use Throwable;

final class EscalationEvaluator
{
    /**
     * @param  array<string, mixed>  $stepOutput
     */
    public function evaluate(
        string $stepId,
        string $error,
        array $stepOutput,
        string $originalPrompt,
        int $attempt,
        int $maxRetries,
    ): SupervisorDecisionData {
        try {
            $response = Atlas::agent((string) config('conductor.escalation.agent', 'conductor-supervisor'))
                ->message($this->buildPrompt($stepId, $error, $stepOutput, $originalPrompt, $attempt, $maxRetries))
                ->asText();

            $parsed = json_decode($response->text, true);

            if (! is_array($parsed)) {
                return new SupervisorDecisionData(
                    action: 'fail',
                    reason: 'Escalation failed: AI returned invalid JSON.',
                );
            }

            $action = $parsed['action'] ?? 'fail';

            if (! in_array($action, ['retry', 'skip', 'fail'], true)) {
                return new SupervisorDecisionData(
                    action: 'fail',
                    reason: 'Escalation failed: AI returned an unsupported action.',
                );
            }

            if ($action === 'retry' && $attempt >= $maxRetries) {
                return new SupervisorDecisionData(
                    action: 'fail',
                    reason: trim(($parsed['reason'] ?? 'Escalation requested retry.').' (max retries exceeded)'),
                );
            }

            return new SupervisorDecisionData(
                action: $action,
                reason: $parsed['reason'] ?? 'AI escalation decision.',
                modified_prompt: is_string($parsed['modified_prompt'] ?? null)
                    ? $parsed['modified_prompt']
                    : null,
            );
        } catch (Throwable $exception) {
            return new SupervisorDecisionData(
                action: 'fail',
                reason: sprintf('Escalation failed: %s', $exception->getMessage()),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $stepOutput
     */
    private function buildPrompt(
        string $stepId,
        string $error,
        array $stepOutput,
        string $originalPrompt,
        int $attempt,
        int $maxRetries,
    ): string {
        $encodedOutput = json_encode($stepOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
A Laravel Conductor workflow step failed and now needs a disposition.

Return JSON only with:
{"action":"retry|skip|fail","reason":"...","modified_prompt":"optional"}

Step ID: {$stepId}
Attempt: {$attempt}
Max retries: {$maxRetries}
Error: {$error}

Original prompt:
{$this->truncate($originalPrompt)}

Step output:
{$this->truncate($encodedOutput === false ? '{}' : $encodedOutput)}
PROMPT;
    }

    private function truncate(string $value, int $limit = 500): string
    {
        return mb_strlen($value) <= $limit
            ? $value
            : mb_substr($value, 0, $limit).'...';
    }
}
