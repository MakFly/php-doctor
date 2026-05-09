<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime\PhpStan;

use PhpDoctor\Core\Project\FrameworkContext;

/**
 * Contract for running PHPStan as a sub-process.
 * The production implementation is PhpStanRunner; tests inject a mock.
 */
interface PhpStanRunnerInterface
{
    /**
     * Returns true only if vendor/bin/phpstan is executable in the project root.
     */
    public function isAvailable(FrameworkContext $ctx): bool;

    /**
     * Run PHPStan and return a parsed report, or null on failure.
     * A failure Finding is emitted into the FindingBag when exit code is fatal.
     */
    public function run(FrameworkContext $ctx, ?int $defaultLevel = 5): ?PhpStanReport;
}
