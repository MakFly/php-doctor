<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime\PhpStan;

use JsonException;
use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;

/**
 * Runs PHPStan as a sub-process and parses its JSON output into a PhpStanReport.
 *
 * Availability decision: we return true ONLY when the binary vendor/bin/phpstan
 * exists and is executable. A composer.json entry without a vendor install is not
 * sufficient — we cannot run a binary that does not exist on disk.
 */
final class PhpStanRunner implements PhpStanRunnerInterface
{
    public function __construct(
        private readonly ProcessExecutorInterface $exec,
        private readonly FindingBag               $bag,
    ) {}

    /**
     * Returns true only if vendor/bin/phpstan exists (is executable) in the project root.
     *
     * Design decision: presence in composer.json require/require-dev without an
     * actual vendor install is intentionally ignored — the binary is absent and we
     * cannot execute it. Documenting this here so future maintainers do not relax
     * the check without understanding the reasoning.
     */
    public function isAvailable(FrameworkContext $ctx): bool
    {
        $binary = $ctx->rootPath . '/vendor/bin/phpstan';
        return is_file($binary) && is_executable($binary);
    }

    /**
     * Run PHPStan on the project and return a parsed report, or null on failure.
     *
     * Exit codes:
     *   0 = no errors found
     *   1 = errors found (still valid JSON output)
     *   other = fatal (config error, bad arguments, …) → emit Finding + return null
     *
     * If a phpstan.neon or phpstan.neon.dist config file is present, --level is
     * omitted to let the project decide. Otherwise --level=$defaultLevel is used.
     */
    public function run(FrameworkContext $ctx, ?int $defaultLevel = 5): ?PhpStanReport
    {
        if (!$this->isAvailable($ctx)) {
            return null;
        }

        $binary = './vendor/bin/phpstan';
        $args   = ['analyse', ...$ctx->sourcePaths, '--error-format=json', '--no-progress'];

        $hasConfig = is_file($ctx->rootPath . '/phpstan.neon')
            || is_file($ctx->rootPath . '/phpstan.neon.dist');

        if (!$hasConfig && $defaultLevel !== null) {
            $args[] = '--level=' . $defaultLevel;
        }

        $command = [$binary, ...$args];
        $result  = $this->exec->run($command, $ctx->rootPath, 120);

        // Exit code 0 = success, 1 = errors found — both are valid for parsing.
        // Any other code (e.g. 2, -1) means PHPStan itself failed fatally.
        if ($result->exitCode !== 0 && $result->exitCode !== 1) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.phpstan.failure',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'PHPStan a échoué (exit code ' . $result->exitCode . ')',
                file:     null,
                line:     null,
                fixHint:  substr($result->stderr, 0, 500) ?: null,
            ));
            return null;
        }

        try {
            /** @var array<mixed> $json */
            $json = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.phpstan.failure',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'PHPStan output not parseable (invalid JSON)',
                file:     null,
                line:     null,
            ));
            return null;
        }

        $messages = [];
        /** @var array<string, array{messages: array<array{message:string, line?:int|null, ignorable?:bool, identifier?:string|null, tip?:string|null}>}> $files */
        $files = $json['files'] ?? [];
        foreach ($files as $file => $entry) {
            foreach ($entry['messages'] as $m) {
                $messages[] = [
                    'file'       => $file,
                    'line'       => $m['line'] ?? null,
                    'message'    => $m['message'],
                    'identifier' => $m['identifier'] ?? null,
                    'ignorable'  => $m['ignorable'] ?? true,
                    'tip'        => $m['tip'] ?? null,
                ];
            }
        }

        /** @var array{file_errors?: int, errors?: int} $totals */
        $totals = $json['totals'] ?? [];
        $total  = ($totals['file_errors'] ?? 0) + ($totals['errors'] ?? 0);

        return new PhpStanReport($total, $messages);
    }
}
