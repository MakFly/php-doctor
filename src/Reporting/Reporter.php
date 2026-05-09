<?php

declare(strict_types=1);

namespace PhpDoctor\Reporting;

use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\FrameworkContext;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Contract for all output reporters.
 *
 * A reporter is responsible solely for formatting and emitting the result of a
 * scan. It knows nothing about rules or how findings were produced.
 *
 * The returned integer is a suggested exit code. Reporters that do not implement
 * CI-threshold logic always return 0; threshold enforcement is Phase 9.
 */
interface Reporter
{
    /**
     * Render the scan results to the given output.
     *
     * @param FindingBag       $bag    All findings produced during the scan.
     * @param Score            $score  Computed score for the project.
     * @param FrameworkContext $ctx    Project metadata (framework, paths, composer data).
     * @param OutputInterface  $output Console output handle.
     *
     * @return int Suggested exit code (0 = success; non-zero reserved for Phase 9 CI mode).
     */
    public function render(
        FindingBag       $bag,
        Score            $score,
        FrameworkContext $ctx,
        OutputInterface  $output,
    ): int;
}
