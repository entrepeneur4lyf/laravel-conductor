<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Definitions;

use Entrepeneur4lyf\LaravelConductor\Contracts\DefinitionRepository;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowDefinitionData;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlWorkflowDefinitionRepository implements DefinitionRepository
{
    /**
     * Keys on the root `defaults` block that may be propagated into
     * individual steps when the step does not declare them explicitly.
     * Any key not in this list is rejected at load time so hosts do not
     * accidentally override step identity fields via defaults.
     */
    private const MERGE_ELIGIBLE_DEFAULTS = [
        'retries',
        'timeout',
        'tools',
        'provider_tools',
        'meta',
    ];

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function load(string $workflow): LoadedWorkflowDefinition
    {
        $sourcePath = $this->resolvePath($workflow);
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        $payload = match ($extension) {
            'json' => $this->loadJson($sourcePath),
            'yaml', 'yml' => $this->loadYaml($sourcePath),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported workflow definition format [%s].',
                $extension
            )),
        };

        if (! is_array($payload)) {
            throw new \InvalidArgumentException(sprintf(
                'Workflow definition [%s] must decode to an object payload.',
                $sourcePath
            ));
        }

        $payload = $this->applyDefaults($payload, $sourcePath);

        return new LoadedWorkflowDefinition(
            definition: WorkflowDefinitionData::from($payload),
            sourcePath: $sourcePath,
        );
    }

    /**
     * Merge the workflow root `defaults` block into each step that does
     * not declare the same field explicitly. Identity fields on the step
     * (id, agent, prompt_template, output_schema) are NOT merge-eligible
     * and are rejected from the defaults block with a clear error so the
     * failure mode is "loud invalid configuration" rather than "silent
     * identity collision." Merges operate at the raw array level so the
     * distinction between "explicitly set" and "using the DTO default"
     * is preserved.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyDefaults(array $payload, string $sourcePath): array
    {
        $defaults = $payload['defaults'] ?? [];

        if (! is_array($defaults) || $defaults === []) {
            return $payload;
        }

        $invalidKeys = array_diff(array_keys($defaults), self::MERGE_ELIGIBLE_DEFAULTS);

        if ($invalidKeys !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Workflow definition [%s] declares unsupported keys in defaults: [%s]. '
                .'Allowed keys: [%s]. Step-identity fields (id, agent, prompt_template, '
                .'output_schema) cannot be set via defaults.',
                $sourcePath,
                implode(', ', $invalidKeys),
                implode(', ', self::MERGE_ELIGIBLE_DEFAULTS),
            ));
        }

        if (! isset($payload['steps']) || ! is_array($payload['steps'])) {
            return $payload;
        }

        $payload['steps'] = array_map(
            function ($step) use ($defaults): mixed {
                if (! is_array($step)) {
                    return $step;
                }

                foreach ($defaults as $key => $value) {
                    if (! array_key_exists($key, $step)) {
                        $step[$key] = $value;
                    }
                }

                return $step;
            },
            $payload['steps'],
        );

        return $payload;
    }

    public function resolvePath(string $workflow): string
    {
        $candidates = [];

        if ($this->hasKnownExtension($workflow)) {
            $candidates[] = $workflow;
        } else {
            foreach (['yaml', 'yml', 'json'] as $extension) {
                $candidates[] = sprintf('%s.%s', $workflow, $extension);
            }
        }

        $configuredRoots = (array) $this->config->get('conductor.definitions.paths', []);
        $searchRoots = match (true) {
            $this->isAbsolutePath($workflow) => [''],
            $this->isBareWorkflowName($workflow) => array_merge($configuredRoots, ['']),
            default => array_merge([''], $configuredRoots),
        };

        foreach ($searchRoots as $root) {
            foreach ($candidates as $candidate) {
                $path = $root === ''
                    ? $candidate
                    : rtrim((string) $root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($candidate, DIRECTORY_SEPARATOR);

                if (! is_file($path)) {
                    continue;
                }

                $resolved = realpath($path);

                if ($resolved !== false) {
                    return $resolved;
                }
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Workflow definition [%s] could not be found.',
            $workflow
        ));
    }

    private function loadJson(string $sourcePath): mixed
    {
        $contents = file_get_contents($sourcePath);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read workflow definition [%s].', $sourcePath));
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                sprintf('Workflow definition [%s] contains invalid JSON.', $sourcePath),
                previous: $exception,
            );
        }

        return $decoded;
    }

    private function loadYaml(string $sourcePath): mixed
    {
        try {
            $decoded = Yaml::parseFile($sourcePath);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException(
                sprintf('Workflow definition [%s] contains invalid YAML.', $sourcePath),
                previous: $exception,
            );
        }

        return $decoded;
    }

    private function hasKnownExtension(string $workflow): bool
    {
        return in_array(strtolower(pathinfo($workflow, PATHINFO_EXTENSION)), ['yaml', 'yml', 'json'], true);
    }

    private function isBareWorkflowName(string $workflow): bool
    {
        return ! $this->isAbsolutePath($workflow)
            && strpbrk($workflow, '/\\') === false;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }
}
