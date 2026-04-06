<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Execution;

use Atlasphp\Atlas\Atlas;
use Atlasphp\Atlas\Responses\StructuredResponse;
use Atlasphp\Atlas\Responses\TextResponse;
use Atlasphp\Atlas\Schema\Schema;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStepExecutor;
use Entrepeneur4lyf\LaravelConductor\Data\StepInputData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use RuntimeException;

final class AtlasStepExecutor implements WorkflowStepExecutor
{
    public function execute(string $agent, StepInputData $input): StepOutputData
    {
        $request = Atlas::agent($agent)
            ->withMeta($input->meta)
            ->message($input->rendered_prompt);

        $schemaPath = $this->schemaPath($input);

        if ($schemaPath !== null) {
            $request->withSchema($this->schemaFromPath($schemaPath));

            /** @var StructuredResponse $response */
            $response = $request->asStructured();

            return $this->toStructuredOutput($input, $response);
        }

        /** @var TextResponse $response */
        $response = $request->asText();

        return $this->toTextOutput($input, $response);
    }

    private function schemaPath(StepInputData $input): ?string
    {
        $schemaPath = $input->meta['output_schema_path'] ?? null;

        if (! is_string($schemaPath) || $schemaPath === '') {
            return null;
        }

        return $schemaPath;
    }

    private function schemaFromPath(string $schemaPath): Schema
    {
        if (! is_file($schemaPath)) {
            throw new RuntimeException(sprintf('Resolved output schema path [%s] does not exist.', $schemaPath));
        }

        $decoded = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Output schema at [%s] must decode to an array.', $schemaPath));
        }

        return new Schema(
            pathinfo($schemaPath, PATHINFO_FILENAME),
            sprintf('Conductor output schema from [%s]', $schemaPath),
            $decoded,
        );
    }

    private function toStructuredOutput(StepInputData $input, StructuredResponse $response): StepOutputData
    {
        return new StepOutputData(
            step_id: $input->step_id,
            run_id: $input->run_id,
            status: 'completed',
            payload: $response->structured,
            metadata: $this->metadata($response->usage->toArray(), $response->usage->totalTokens(), $response->finishReason->value, 'structured'),
        );
    }

    private function toTextOutput(StepInputData $input, TextResponse $response): StepOutputData
    {
        return new StepOutputData(
            step_id: $input->step_id,
            run_id: $input->run_id,
            status: 'completed',
            payload: [
                'text' => $response->text,
            ],
            metadata: $this->metadata($response->usage->toArray(), $response->usage->totalTokens(), $response->finishReason->value, 'text'),
        );
    }

    /**
     * @param  array<string, int>  $usage
     * @return array<string, mixed>
     */
    private function metadata(array $usage, int $tokensUsed, string $finishReason, string $responseType): array
    {
        return [
            'response_type' => $responseType,
            'usage' => $usage,
            'tokens_used' => $tokensUsed,
            'finish_reason' => $finishReason,
        ];
    }
}
