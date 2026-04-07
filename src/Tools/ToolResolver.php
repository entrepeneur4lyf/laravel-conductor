<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Tools;

use Atlasphp\Atlas\Tools\Tool;
use RuntimeException;

/**
 * Resolves tool identifiers from workflow YAML into Atlas Tool class names.
 *
 * Supports three resolution strategies:
 *  1. Explicit mapping via `conductor.tools` config
 *  2. Fully-qualified class names passed directly in YAML
 *  3. Convention-based: `snake_case` name → `App\Tools\{StudlyCase}Tool`
 *
 * All resolved classes are validated as subclasses of Atlas\Tools\Tool.
 * Actual instantiation is delegated to Atlas, which resolves tool
 * class strings through the Laravel container itself when building
 * the outgoing request (see Atlas\Pending\AgentRequest::resolveTools).
 */
final class ToolResolver
{
    /** @var array<string, class-string<Tool>> */
    private array $registry;

    private string $conventionNamespace;

    public function __construct()
    {
        /** @var array<string, class-string<Tool>> $configured */
        $configured = config('conductor.tools.map', []);
        $this->registry = $configured;
        $this->conventionNamespace = config('conductor.tools.namespace', 'App\\Tools');
    }

    /**
     * Register a tool mapping at runtime.
     *
     * @param  class-string<Tool>  $className
     */
    public function register(string $name, string $className): void
    {
        $this->validateToolClass($className, $name);
        $this->registry[$name] = $className;
    }

    /**
     * Resolve a single tool identifier to a class name.
     *
     * @return class-string<Tool>
     */
    public function resolve(string $identifier): string
    {
        // 1. Check explicit registry. Classes loaded from the config map
        //    are not pre-validated at construction time (so a missing
        //    class does not crash the provider bootstrapping), so we
        //    validate on resolve instead.
        if (isset($this->registry[$identifier])) {
            $this->validateToolClass($this->registry[$identifier], $identifier);

            return $this->registry[$identifier];
        }

        // 2. If it looks like a FQCN and the class exists, use it directly
        if (str_contains($identifier, '\\') && class_exists($identifier)) {
            $this->validateToolClass($identifier, $identifier);

            return $identifier;
        }

        // 3. Convention: snake_case → StudlyCase + Tool suffix
        $studly = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $identifier)));
        $conventionClass = $this->conventionNamespace.'\\'.$studly.'Tool';

        if (class_exists($conventionClass)) {
            $this->validateToolClass($conventionClass, $identifier);

            return $conventionClass;
        }

        // 4. Try without "Tool" suffix in case the class already has it
        $conventionClassAlt = $this->conventionNamespace.'\\'.$studly;
        if (class_exists($conventionClassAlt)) {
            $this->validateToolClass($conventionClassAlt, $identifier);

            return $conventionClassAlt;
        }

        throw new RuntimeException(sprintf(
            'Tool [%s] could not be resolved. Tried: config map, FQCN, [%s], [%s]. '
            .'Register it in conductor.tools.map or create the class.',
            $identifier,
            $conventionClass,
            $conventionClassAlt,
        ));
    }

    /**
     * Resolve multiple tool identifiers to class names.
     *
     * @param  array<int, string>  $identifiers
     * @return array<int, class-string<Tool>>
     */
    public function resolveMany(array $identifiers): array
    {
        return array_map(fn (string $id): string => $this->resolve($id), $identifiers);
    }

    /**
     * Check whether a tool identifier can be resolved.
     */
    public function has(string $identifier): bool
    {
        try {
            $this->resolve($identifier);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Return the full registry (config + runtime registrations).
     *
     * @return array<string, class-string<Tool>>
     */
    public function all(): array
    {
        return $this->registry;
    }

    /**
     * @param  class-string  $className
     */
    private function validateToolClass(string $className, string $identifier): void
    {
        if (! is_subclass_of($className, Tool::class)) {
            throw new RuntimeException(sprintf(
                'Tool [%s] resolved to [%s] which does not extend [%s].',
                $identifier,
                $className,
                Tool::class,
            ));
        }
    }
}
