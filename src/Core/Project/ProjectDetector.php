<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Project;

/**
 * Detects the framework used in a PHP project by reading its composer.json.
 *
 * Detection is purely static: no CLI binary is executed.
 */
final class ProjectDetector
{
    /**
     * Detect the project at the given path and return a populated FrameworkContext.
     *
     * @throws \InvalidArgumentException if $path does not exist.
     * @throws \RuntimeException         if composer.json is absent.
     * @throws \JsonException            if composer.json cannot be decoded.
     */
    public function detect(string $path): FrameworkContext
    {
        $absolutePath = realpath($path);
        if ($absolutePath === false) {
            throw new \InvalidArgumentException(
                "Path does not exist or is not accessible: {$path}"
            );
        }

        $composerFile = $absolutePath . '/composer.json';
        if (!is_file($composerFile)) {
            throw new \RuntimeException(
                "composer.json not found at {$absolutePath}"
            );
        }

        $content = file_get_contents($composerFile);
        if ($content === false) {
            throw new \RuntimeException(
                "Unable to read composer.json at {$absolutePath}"
            );
        }

        /** @var array<mixed> $composerData */
        $composerData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $framework = $this->detectFramework($composerData);
        $consoleBinary = $this->detectConsoleBinary($absolutePath, $framework);
        $sourcePaths = $this->detectSourcePaths($absolutePath, $framework);

        return new FrameworkContext(
            $framework,
            $absolutePath,
            $consoleBinary,
            $composerData,
            $sourcePaths,
        );
    }

    /**
     * @param array<mixed> $composerData
     */
    private function detectFramework(array $composerData): Framework
    {
        $require    = $composerData['require'] ?? [];
        $requireDev = $composerData['require-dev'] ?? [];

        $allDeps = array_merge(
            is_array($require) ? $require : [],
            is_array($requireDev) ? $requireDev : [],
        );

        if (isset($allDeps['laravel/framework'])) {
            return Framework::Laravel;
        }

        if (isset($allDeps['symfony/framework-bundle'])) {
            return Framework::Symfony;
        }

        return Framework::Generic;
    }

    private function detectConsoleBinary(string $rootPath, Framework $framework): ?string
    {
        $candidate = match($framework) {
            Framework::Symfony => $rootPath . '/bin/console',
            Framework::Laravel => $rootPath . '/artisan',
            Framework::Generic => null,
        };

        if ($candidate !== null && is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function detectSourcePaths(string $rootPath, Framework $framework): array
    {
        $candidates = [
            $rootPath . '/src',
            $rootPath . '/app',
        ];

        $existing = array_filter($candidates, 'is_dir');
        $existing = array_values($existing);

        if (empty($existing)) {
            // Generic fallback: use the project root itself
            return [$rootPath];
        }

        return $existing;
    }
}
