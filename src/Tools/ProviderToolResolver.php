<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Tools;

use Atlasphp\Atlas\Providers\Tools\CodeExecution;
use Atlasphp\Atlas\Providers\Tools\CodeInterpreter;
use Atlasphp\Atlas\Providers\Tools\FileSearch;
use Atlasphp\Atlas\Providers\Tools\GoogleSearch;
use Atlasphp\Atlas\Providers\Tools\ProviderTool;
use Atlasphp\Atlas\Providers\Tools\WebFetch;
use Atlasphp\Atlas\Providers\Tools\WebSearch;
use Atlasphp\Atlas\Providers\Tools\XSearch;
use RuntimeException;

/**
 * Resolves provider tool declarations from workflow YAML into Atlas ProviderTool instances.
 *
 * Provider tools are native capabilities offered by AI providers (OpenAI, Google, xAI)
 * that run server-side. They are declared in workflow YAML as either:
 *
 *   - A string type:  `web_search`
 *   - An object:       `{ type: web_search, max_results: 5, locale: en-US }`
 *
 * This resolver maps them to the corresponding Atlas ProviderTool class and passes
 * through any configuration options.
 */
final class ProviderToolResolver
{
    /**
     * Known provider tool types → Atlas class.
     *
     * @var array<string, class-string<ProviderTool>>
     */
    private const TYPE_MAP = [
        'web_search' => WebSearch::class,
        'web_fetch' => WebFetch::class,
        'file_search' => FileSearch::class,
        'code_interpreter' => CodeInterpreter::class,
        'google_search' => GoogleSearch::class,
        'code_execution' => CodeExecution::class,
        'x_search' => XSearch::class,
    ];

    /**
     * Resolve a single provider tool declaration.
     *
     * @param  string|array<string, mixed>  $declaration
     */
    public function resolve(string|array $declaration): ProviderTool
    {
        if (is_string($declaration)) {
            return $this->instantiate($declaration, []);
        }

        $type = $declaration['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw new RuntimeException(
                'Provider tool declaration must include a "type" key. Got: '.json_encode($declaration),
            );
        }

        // Everything except 'type' is passed as named constructor arguments
        $options = array_diff_key($declaration, ['type' => true]);

        return $this->instantiate($type, $options);
    }

    /**
     * Resolve multiple provider tool declarations.
     *
     * @param  array<int, string|array<string, mixed>>  $declarations
     * @return array<int, ProviderTool>
     */
    public function resolveMany(array $declarations): array
    {
        return array_map(fn (string|array $decl): ProviderTool => $this->resolve($decl), $declarations);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function instantiate(string $type, array $options): ProviderTool
    {
        $normalizedType = strtolower(str_replace(['-', ' '], '_', $type));
        $class = self::TYPE_MAP[$normalizedType] ?? null;

        if ($class === null) {
            // Allow FQCN passthrough
            if (class_exists($type) && is_subclass_of($type, ProviderTool::class)) {
                $class = $type;
            } else {
                throw new RuntimeException(sprintf(
                    'Unknown provider tool type [%s]. Available: %s',
                    $type,
                    implode(', ', array_keys(self::TYPE_MAP)),
                ));
            }
        }

        if ($options === []) {
            return new $class;
        }

        // Map snake_case YAML keys to camelCase constructor params
        $camelOptions = [];
        foreach ($options as $key => $value) {
            $camelKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            $camelOptions[$camelKey] = $value;
        }

        return new $class(...$camelOptions);
    }
}
