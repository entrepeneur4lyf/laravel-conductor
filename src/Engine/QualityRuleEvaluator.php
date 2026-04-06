<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Engine;

final class QualityRuleEvaluator
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<int, string>|null  $rules
     * @return object{passed: bool, failures: array<int, string>}
     */
    public function evaluate(array $output, ?array $rules): object
    {
        if ($rules === null || $rules === []) {
            return (object) ['passed' => true, 'failures' => []];
        }

        $failures = [];

        foreach ($rules as $rule) {
            if (! $this->evaluateRule($output, $rule)) {
                $failures[] = sprintf('Quality rule failed: %s', $rule);
            }
        }

        return (object) ['passed' => $failures === [], 'failures' => $failures];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function evaluateRule(array $output, string $rule): bool
    {
        if (! preg_match('/^output\.(.+?)\s*(>=|<=|>|<|==|!=)\s*(.+)$/', $rule, $matches)) {
            return false;
        }

        $path = $matches[1];
        $operator = $matches[2];
        $expected = $this->normalizeScalar(trim($matches[3]));
        $actual = data_get($output, $path);

        if ($actual === null) {
            return false;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            $actual = (float) $actual;
            $expected = (float) $expected;
        }

        if ($operator === '>=') {
            return $actual >= $expected;
        }

        if ($operator === '<=') {
            return $actual <= $expected;
        }

        if ($operator === '>') {
            return $actual > $expected;
        }

        if ($operator === '<') {
            return $actual < $expected;
        }

        if ($operator === '==') {
            return $actual == $expected;
        }

        return $actual != $expected;
    }

    private function normalizeScalar(string $value): mixed
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => is_numeric($value) ? (float) $value : trim($value, '\'"'),
        };
    }
}
