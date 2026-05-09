<?php

declare(strict_types=1);

namespace PhpDoctor\Rules\Symfony;

use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Rule;
use PhpDoctor\Core\Rule\Severity;

/**
 * Detects routes whose controller class no longer exists in the service
 * container definitions or on disk (PSR-4 heuristic).
 *
 * A route is considered "orphan" when:
 *   - Its _controller resolves to a class under App\Controller\
 *   - AND that class is NOT in runtime->services keys
 *   - AND the corresponding file does NOT exist on disk
 */
final class OrphanRouteRule implements Rule
{
    public function id(): string
    {
        return 'symfony.architecture.orphan-route';
    }

    public function category(): Category
    {
        return Category::Architecture;
    }

    public function severity(): Severity
    {
        return Severity::Low;
    }

    public function appliesTo(FrameworkContext $ctx): bool
    {
        return $ctx->framework === Framework::Symfony;
    }

    /**
     * @return iterable<Finding>
     */
    public function analyze(AnalysisInput $input): iterable
    {
        if ($input->runtime === null || $input->runtime->routes === null) {
            return;
        }

        $services = $input->runtime->services ?? [];
        $root     = $input->ctx->rootPath;

        foreach ($input->runtime->routes as $routeName => $routeDef) {
            if (!is_array($routeDef)) {
                continue;
            }

            $className = $this->resolveControllerClass($routeDef);
            if ($className === null) {
                continue;
            }

            // Only inspect App\Controller\ controllers
            if (!str_starts_with($className, 'App\\Controller\\')) {
                continue;
            }

            // Check 1: present in service container definitions
            if (array_key_exists($className, $services)) {
                continue;
            }

            // Check 2: file exists on disk (PSR-4 heuristic)
            $filePath = $this->classToFilePath($className, $root);
            if ($filePath !== null && is_file($filePath)) {
                continue;
            }

            yield new Finding(
                ruleId:   $this->id(),
                severity: $this->severity(),
                category: $this->category(),
                message:  "Orphan route: controller {$className} not found (route '{$routeName}').",
                file:     null,
                line:     null,
                fixHint:  "Remove or update the route '{$routeName}', or restore the controller class.",
            );
        }
    }

    /**
     * @param array<string, mixed> $routeDef
     */
    private function resolveControllerClass(array $routeDef): ?string
    {
        $controller = $routeDef['defaults']['_controller']
            ?? $routeDef['class']
            ?? null;

        if (!is_string($controller) || $controller === '') {
            return null;
        }

        if (str_contains($controller, '::')) {
            [$class] = explode('::', $controller, 2);
            return trim($class);
        }

        return trim($controller);
    }

    private function classToFilePath(string $className, string $rootPath): ?string
    {
        if (!str_starts_with($className, 'App\\')) {
            return null;
        }

        $relative = str_replace('\\', '/', substr($className, 4));
        return rtrim($rootPath, '/') . '/src/' . $relative . '.php';
    }
}
