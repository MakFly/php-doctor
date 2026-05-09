<?php

declare(strict_types=1);

namespace PhpDoctor\Reporting\Console;

use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Reporting\Reporter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Renders scan results as a human-readable console report using SymfonyStyle.
 */
final class ConsoleReporter implements Reporter
{
    private const MAX_FINDINGS_DISPLAYED = 50;

    public function render(
        FindingBag       $bag,
        Score            $score,
        FrameworkContext $ctx,
        OutputInterface  $output,
    ): int {
        $input = new ArrayInput([]);
        $io    = new SymfonyStyle($input, $output);

        // --- Header ---
        $projectName = $this->resolveProjectName($ctx);
        $io->title("php-doctor — {$projectName}");
        $io->note(sprintf(
            'Framework: %s · Path: %s',
            $ctx->framework->label(),
            $ctx->rootPath,
        ));

        // --- Global Score ---
        $globalScore = $score->global;
        $coloredScore = match (true) {
            $globalScore >= 80 => "<info>{$globalScore}/100</info>",
            $globalScore >= 60 => "<comment>{$globalScore}/100</comment>",
            default            => "<error>{$globalScore}/100</error>",
        };
        $output->writeln('');
        $output->writeln(" Global Score: {$coloredScore}");
        $output->writeln('');

        // Category score table
        $rows = [];
        foreach ($score->byCategory as $catKey => $catScore) {
            $findingsInCat = count(($bag->byCategory()[$catKey] ?? []));
            $coloredCatScore = match (true) {
                $catScore >= 80 => "<info>{$catScore}</info>",
                $catScore >= 60 => "<comment>{$catScore}</comment>",
                default         => "<error>{$catScore}</error>",
            };
            $rows[] = [$catKey, $coloredCatScore, (string) $findingsInCat];
        }
        $io->table(['Category', 'Score', 'Findings'], $rows);

        // --- Findings ---
        $io->section('Findings');

        $allFindings = iterator_to_array($bag->all(), false);

        if (count($allFindings) === 0) {
            $io->success('No findings — clean run!');
        } else {
            // Sort by severity order: Critical → High → Medium → Low → Info
            $severityOrder = [
                Severity::Critical->value => 0,
                Severity::High->value     => 1,
                Severity::Medium->value   => 2,
                Severity::Low->value      => 3,
                Severity::Info->value     => 4,
            ];
            usort($allFindings, static function (Finding $a, Finding $b) use ($severityOrder): int {
                return ($severityOrder[$a->severity->value] ?? 99) <=> ($severityOrder[$b->severity->value] ?? 99);
            });

            $truncated = false;
            $displayed = $allFindings;
            if (count($allFindings) > self::MAX_FINDINGS_DISPLAYED) {
                $displayed  = array_slice($allFindings, 0, self::MAX_FINDINGS_DISPLAYED);
                $truncated  = true;
            }

            $findingRows = [];
            foreach ($displayed as $finding) {
                $coloredSeverity = match ($finding->severity) {
                    Severity::Critical => "<error>{$finding->severity->value}</error>",
                    Severity::High     => "<error>{$finding->severity->value}</error>",
                    Severity::Medium   => "<comment>{$finding->severity->value}</comment>",
                    Severity::Low      => "<info>{$finding->severity->value}</info>",
                    Severity::Info     => $finding->severity->value,
                };
                $location = $finding->file ?? '';
                if ($finding->line !== null) {
                    $location .= ':' . $finding->line;
                }
                $findingRows[] = [
                    $coloredSeverity,
                    $finding->ruleId,
                    $location,
                    $finding->message,
                ];
            }

            $io->table(['Severity', 'Rule', 'Location', 'Message'], $findingRows);

            if ($truncated) {
                $remaining = count($allFindings) - self::MAX_FINDINGS_DISPLAYED;
                $io->note("… {$remaining} more (use --format=json for full list)");
            }
        }

        // --- Footer: counts by severity ---
        $bySeverity = $bag->countBySeverity();
        $footerRows = [];
        foreach ([Severity::Critical, Severity::High, Severity::Medium, Severity::Low, Severity::Info] as $sev) {
            $count = $bySeverity[$sev->value] ?? 0;
            if ($count > 0) {
                $footerRows[] = [$sev->value, (string) $count];
            }
        }
        if (!empty($footerRows)) {
            $io->section('Summary');
            $io->table(['Severity', 'Count'], $footerRows);
        }

        return 0;
    }

    private function resolveProjectName(FrameworkContext $ctx): string
    {
        $name = $ctx->composerData['name'] ?? null;
        if (is_string($name) && $name !== '') {
            return $name;
        }
        return basename($ctx->rootPath);
    }
}
