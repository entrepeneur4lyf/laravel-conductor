<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Engine;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;

final class TemplateRenderer
{
    public function resolvePath(string $templateReference, string $workflowSourcePath): string
    {
        return $this->resolveFilePath($templateReference, $workflowSourcePath);
    }

    public function assertValidSyntax(string $templatePath): void
    {
        $this->assertValidSyntaxContents($this->readFileContents($templatePath), $templatePath);
    }

    public function assertValidSyntaxContents(string $templateContents, ?string $displayPath = null): void
    {
        $templatePath = $displayPath ?? 'inline-template';
        $this->assertStandaloneTemplate($templateContents, $templatePath);
        $twig = $this->createEnvironment(new ArrayLoader());

        try {
            $twig->createTemplate($templateContents);
        } catch (SyntaxError $exception) {
            throw new \InvalidArgumentException(sprintf(
                'Prompt template [%s] contains invalid Twig syntax.',
                $templatePath
            ), previous: $exception);
        }
    }

    private function assertStandaloneTemplate(string $templateContents, string $templatePath): void
    {
        $unsupportedReferences = [];

        if (preg_match_all('/{%\s*(include|extends|embed|use|import|from)\b/i', $templateContents, $matches) > 0) {
            $unsupportedReferences = [...$unsupportedReferences, ...$matches[1]];
        }

        if (preg_match_all('/\b(include|source)\s*\(/i', $templateContents, $matches) > 0) {
            $unsupportedReferences = [...$unsupportedReferences, ...$matches[1]];
        }

        $unsupportedReferences = array_values(array_unique(array_map('strtolower', $unsupportedReferences)));

        if ($unsupportedReferences === []) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Prompt template [%s] uses unsupported Twig template references [%s].',
            $templatePath,
            implode(', ', $unsupportedReferences),
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function render(string $templatePath, array $context = []): string
    {
        $loader = new FilesystemLoader(dirname($templatePath));
        $twig = $this->createEnvironment($loader);

        return $twig->render(basename($templatePath), $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function renderContents(string $templateContents, array $context = [], ?string $displayPath = null): string
    {
        $templateName = $displayPath === null ? 'inline-template' : basename($displayPath);
        $twig = $this->createEnvironment(new ArrayLoader([
            $templateName => $templateContents,
        ]));

        return $twig->render($templateName, $context);
    }

    private function resolveFilePath(string $reference, string $workflowSourcePath): string
    {
        $candidate = $this->isAbsolutePath($reference)
            ? $reference
            : dirname($workflowSourcePath).DIRECTORY_SEPARATOR.$reference;

        $resolved = realpath($candidate);

        if ($resolved === false || ! is_file($resolved) || ! $this->isWithinWorkflowTree($resolved, $workflowSourcePath)) {
            throw new \InvalidArgumentException(sprintf(
                'Prompt template [%s] could not be resolved from [%s].',
                $reference,
                $workflowSourcePath
            ));
        }

        return $resolved;
    }

    private function readFileContents(string $templatePath): string
    {
        $contents = file_get_contents($templatePath);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read template [%s].', $templatePath));
        }

        return $contents;
    }

    private function createEnvironment(FilesystemLoader|ArrayLoader $loader): Environment
    {
        return new Environment($loader, [
            'cache' => false,
            'autoescape' => false,
        ]);
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
