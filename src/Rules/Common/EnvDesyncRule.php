<?php

declare(strict_types=1);

namespace PhpDoctor\Rules\Common;

use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Rule;
use PhpDoctor\Core\Rule\Severity;

/**
 * Compares keys in .env.example vs .env.
 * Emits one Finding per key that exists in one file but not the other.
 *
 * Skips silently if neither file exists.
 * This rule does NOT inspect the AST — it reads the filesystem directly.
 */
final class EnvDesyncRule implements Rule
{
    public function id(): string
    {
        return 'common.hygiene.env-desync';
    }

    public function category(): Category
    {
        return Category::Hygiene;
    }

    public function severity(): Severity
    {
        return Severity::Medium;
    }

    public function appliesTo(FrameworkContext $ctx): bool
    {
        return true;
    }

    /**
     * @return iterable<Finding>
     */
    public function analyze(AnalysisInput $input): iterable
    {
        $root    = $input->ctx->rootPath;
        $example = $root . '/.env.example';
        $env     = $root . '/.env';

        $exampleExists = is_file($example);
        $envExists     = is_file($env);

        // Skip if neither exists
        if (!$exampleExists && !$envExists) {
            return;
        }

        $exampleKeys = $exampleExists ? $this->parseEnvKeys($example) : [];
        $envKeys     = $envExists     ? $this->parseEnvKeys($env)     : [];

        // Keys in .env.example but missing from .env
        foreach (array_diff($exampleKeys, $envKeys) as $key) {
            yield new Finding(
                ruleId:   $this->id(),
                severity: $this->severity(),
                category: $this->category(),
                message:  "Key '{$key}' exists in .env.example but not in .env",
                file:     $env,
                line:     null,
                fixHint:  "Add '{$key}' to your .env file.",
            );
        }

        // Keys in .env but missing from .env.example
        foreach (array_diff($envKeys, $exampleKeys) as $key) {
            yield new Finding(
                ruleId:   $this->id(),
                severity: $this->severity(),
                category: $this->category(),
                message:  "Key '{$key}' exists in .env but not in .env.example",
                file:     $example,
                line:     null,
                fixHint:  "Add '{$key}' to your .env.example file (use a placeholder value).",
            );
        }
    }

    /**
     * Parse key names from an env file.
     * Ignores comment lines (#), blank lines, and lines without '='.
     *
     * @return string[]
     */
    private function parseEnvKeys(string $path): array
    {
        $keys  = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $keys;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key] = explode('=', $line, 2);
            $key   = trim($key);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_unique($keys);
    }
}
