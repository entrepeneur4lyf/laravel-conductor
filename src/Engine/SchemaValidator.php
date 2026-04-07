<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Engine;

use JsonSchema\Validator;

final class SchemaValidator
{
    public function resolvePath(string $schemaReference, string $workflowSourcePath): string
    {
        $candidate = match (true) {
            str_starts_with($schemaReference, '@schemas/') => dirname($workflowSourcePath)
                .DIRECTORY_SEPARATOR.'schemas'
                .DIRECTORY_SEPARATOR.substr($schemaReference, strlen('@schemas/')),
            $this->isAbsolutePath($schemaReference) => $schemaReference,
            default => dirname($workflowSourcePath).DIRECTORY_SEPARATOR.$schemaReference,
        };

        $resolved = realpath($candidate);

        if ($resolved === false || ! is_file($resolved) || ! $this->isWithinWorkflowTree($resolved, $workflowSourcePath)) {
            throw new \InvalidArgumentException(sprintf(
                'Output schema [%s] could not be resolved from [%s].',
                $schemaReference,
                $workflowSourcePath
            ));
        }

        return $resolved;
    }

    public function assertValidSchemaFile(string $schemaPath): void
    {
        $this->assertValidSchemaContents($this->readFileContents($schemaPath), $schemaPath);
    }

    public function assertValidSchemaContents(string $schemaContents, string $displayPath = 'inline-schema'): void
    {
        $schema = $this->decodeSchemaContents($schemaContents, $displayPath);
        $this->assertSupportedRefs($schema, $displayPath);

        $validator = new Validator;
        $validator->check($schema, (object) ['$ref' => 'http://json-schema.org/draft-07/schema#']);

        if ($validator->isValid()) {
            return;
        }

        $message = $validator->getErrors()[0]['message'] ?? 'Unknown schema validation error.';

        throw new \InvalidArgumentException(sprintf(
            'Schema file [%s] is not a valid JSON schema: %s',
            $displayPath,
            $message
        ));
    }

    /**
     * @param  array<string, mixed>|object  $payload
     */
    public function validate(object|array $payload, string $schemaPath): void
    {
        $this->validateContents($payload, $this->readFileContents($schemaPath), $schemaPath);
    }

    /**
     * @param  array<string, mixed>|object  $payload
     */
    public function validateContents(object|array $payload, string $schemaContents, string $displayPath = 'inline-schema'): void
    {
        $schema = $this->decodeSchemaContents($schemaContents, $displayPath);
        $normalizedPayload = $this->normalizeJsonLikeValue($payload);
        $validator = new Validator;
        $validator->validate($normalizedPayload, $schema);

        if ($validator->isValid()) {
            return;
        }

        $message = $validator->getErrors()[0]['message'] ?? 'Unknown schema validation error.';

        throw new \InvalidArgumentException(sprintf(
            'Payload does not match schema [%s]: %s',
            $displayPath,
            $message
        ));
    }

    /**
     * Normalize arrays and objects into JSON-like values that the validator can traverse recursively.
     *
     * @return array<mixed>|object|scalar|null
     */
    private function normalizeJsonLikeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = array_map(
                fn (mixed $item): mixed => $this->normalizeJsonLikeValue($item),
                $value
            );

            return array_is_list($value) ? $normalized : (object) $normalized;
        }

        if (is_object($value)) {
            $normalized = [];

            foreach (get_object_vars($value) as $key => $item) {
                $normalized[$key] = $this->normalizeJsonLikeValue($item);
            }

            return (object) $normalized;
        }

        return $value;
    }

    private function readFileContents(string $schemaPath): string
    {
        $contents = file_get_contents($schemaPath);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read schema file [%s].', $schemaPath));
        }

        return $contents;
    }

    private function decodeSchemaContents(string $schemaContents, string $displayPath): object
    {
        try {
            $schema = json_decode($schemaContents, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                sprintf('Schema file [%s] contains invalid JSON.', $displayPath),
                previous: $exception,
            );
        }

        if (! is_object($schema)) {
            throw new \InvalidArgumentException(sprintf(
                'Schema file [%s] must decode to a JSON object.',
                $displayPath
            ));
        }

        return $schema;
    }

    private function assertSupportedRefs(mixed $node, string $displayPath): void
    {
        if (is_object($node)) {
            $ref = property_exists($node, '$ref') ? $node->{'$ref'} : null;

            if (is_string($ref) && ! str_starts_with($ref, '#')) {
                throw new \InvalidArgumentException(sprintf(
                    'Schema file [%s] uses unsupported external $ref [%s].',
                    $displayPath,
                    $ref,
                ));
            }

            foreach (get_object_vars($node) as $value) {
                $this->assertSupportedRefs($value, $displayPath);
            }

            return;
        }

        if (! is_array($node)) {
            return;
        }

        foreach ($node as $value) {
            $this->assertSupportedRefs($value, $displayPath);
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }

    private function isWithinWorkflowTree(string $resolvedPath, string $workflowSourcePath): bool
    {
        $workflowRoot = realpath(dirname($workflowSourcePath)) ?: dirname($workflowSourcePath);

        return $resolvedPath === $workflowRoot
            || str_starts_with($resolvedPath, $workflowRoot.DIRECTORY_SEPARATOR);
    }
}
