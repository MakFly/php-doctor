<?php

declare(strict_types=1);

namespace PhpDoctor\Cli;

use PhpDoctor\Analysis\Ast\AstAnalyzer;
use PhpDoctor\Analysis\Ast\FileWalker;
use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Analysis\Runtime\Composer\ComposerAuditor;
use PhpDoctor\Analysis\Runtime\Laravel\AboutCollector as LaravelAboutCollector;
use PhpDoctor\Analysis\Runtime\Laravel\ConfigCollector as LaravelConfigCollector;
use PhpDoctor\Analysis\Runtime\Laravel\MigrationCollector as LaravelMigrationCollector;
use PhpDoctor\Analysis\Runtime\Laravel\RouteCollector as LaravelRouteCollector;
use PhpDoctor\Analysis\Runtime\PhpStan\PhpStanRunner;
use PhpDoctor\Analysis\Runtime\ProcessExecutor;
use PhpDoctor\Analysis\Runtime\SnapshotBuilder;
use PhpDoctor\Analysis\Runtime\Symfony\BundleCollector as SymfonyBundleCollector;
use PhpDoctor\Analysis\Runtime\Symfony\EventCollector as SymfonyEventCollector;
use PhpDoctor\Analysis\Runtime\Symfony\RouteCollector as SymfonyRouteCollector;
use PhpDoctor\Analysis\Runtime\Symfony\ServiceCollector as SymfonyServiceCollector;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\ProjectDetector;
use PhpDoctor\Reporting\Console\ConsoleReporter;
use PhpDoctor\Reporting\Html\HtmlReporter;
use PhpDoctor\Reporting\Json\JsonReporter;
use PhpDoctor\Reporting\Reporter;
use PhpDoctor\Reporting\Sarif\SarifReporter;
use PhpDoctor\Rules\RuleProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'scan',
    description: 'Scan a PHP project and report health findings.',
)]
final class ScanCommand extends Command
{
    private const VALID_FORMATS = ['console', 'json', 'html', 'sarif'];

    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to the project root to scan.',
                '.'
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: console, json, html.',
                'console'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Write output to file (useful for json/html formats).',
                null
            )
            ->addOption(
                'ci',
                null,
                InputOption::VALUE_NONE,
                'Enable CI mode: defaults output to JSON, enables exit-code thresholds.',
            )
            ->addOption(
                'min-score',
                null,
                InputOption::VALUE_REQUIRED,
                'In --ci mode, exit 1 if the global score is below this value (0–100).',
                null
            )
            ->addOption(
                'fail-on',
                null,
                InputOption::VALUE_REQUIRED,
                'In --ci mode, exit 1 if any finding has at least this severity (critical|high|medium|low|info).',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --- 1. Resolve CI flags ---
        $ciMode   = (bool) $input->getOption('ci');
        $minScore = $input->getOption('min-score') !== null ? (int) $input->getOption('min-score') : null;
        /** @var string|null $failOn */
        $failOn   = $input->getOption('fail-on');

        // Validate --fail-on value when present.
        if ($failOn !== null && !\PhpDoctor\Core\Rule\Severity::tryFrom($failOn)) {
            $io->error(sprintf(
                'Invalid --fail-on value "%s". Valid values: critical, high, medium, low, info.',
                $failOn,
            ));
            return Command::FAILURE;
        }

        // --- 2. Validate format option ---
        /** @var string $format */
        $format = $input->getOption('format');

        // In CI mode, default to JSON if the user did not explicitly set a format.
        if ($ciMode && $format === 'console') {
            $format = 'json';
        }

        if (!in_array($format, self::VALID_FORMATS, true)) {
            $io->error(sprintf(
                'Invalid format "%s". Valid formats: %s.',
                $format,
                implode(', ', self::VALID_FORMATS),
            ));
            return Command::FAILURE;
        }

        /** @var string|null $outputFile */
        $outputFile = $input->getOption('output');

        // --- 2. Detect project ---
        /** @var string $pathArg */
        $pathArg = $input->getArgument('path');
        $path    = $pathArg !== '' ? $pathArg : (string) getcwd();

        try {
            $detector = new ProjectDetector();
            $ctx      = $detector->detect($path);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $io->error('Project detection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // --- 3. Build shared services ---
        $bag  = new FindingBag();
        $exec = new ProcessExecutor();

        // --- 4. Build AST pipeline ---
        $parserPool  = new ParserPool();
        $fileWalker  = new FileWalker();
        $astAnalyzer = new AstAnalyzer($parserPool, $fileWalker);

        // --- 5. Build runtime pipeline ---
        $snapshotBuilder = new SnapshotBuilder(
            symfonyRoutes:     new SymfonyRouteCollector($exec, $bag),
            symfonyServices:   new SymfonyServiceCollector($exec, $bag),
            symfonyEvents:     new SymfonyEventCollector($exec, $bag),
            symfonyBundles:    new SymfonyBundleCollector($exec, $bag),
            laravelRoutes:     new LaravelRouteCollector($exec, $bag),
            laravelAbout:      new LaravelAboutCollector($exec, $bag),
            laravelConfig:     new LaravelConfigCollector($exec, $bag),
            laravelMigrations: new LaravelMigrationCollector($exec, $bag),
            composerAuditor:   new ComposerAuditor($exec, $bag),
        );

        // --- 6. Build PHPStan runner + rule registry ---
        $phpStanRunner = new PhpStanRunner($exec, $bag);
        $phpStanArg    = $phpStanRunner->isAvailable($ctx) ? $phpStanRunner : null;
        $registry      = RuleProvider::buildRegistry($parserPool, $phpStanArg);

        // --- 7. Run AST analysis ---
        $astSnapshot     = $astAnalyzer->analyze($ctx, $bag, []);
        $runtimeSnapshot = $snapshotBuilder->build($ctx, $bag);

        // --- 8. Run rules ---
        $analysisInput = new AnalysisInput($ctx, $astSnapshot, $runtimeSnapshot);
        foreach ($registry->enabledFor($ctx) as $rule) {
            foreach ($rule->analyze($analysisInput) as $finding) {
                $bag->add($finding);
            }
        }

        // --- 9. Compute score ---
        $score = Score::fromBag($bag);

        // --- 10. Select reporter and render ---
        $reporter  = $this->buildReporter($format, $outputFile);
        $exit      = $reporter->render($bag, $score, $ctx, $output);

        // --- 11. CI threshold enforcement (only when --ci is passed) ---
        if ($ciMode) {
            if ($minScore !== null && $score->global < $minScore) {
                $exit = Command::FAILURE;
            }

            if ($failOn !== null) {
                $threshold = \PhpDoctor\Core\Rule\Severity::from($failOn);
                foreach ($bag->all() as $finding) {
                    if ($finding->severity->weight() >= $threshold->weight()) {
                        $exit = Command::FAILURE;
                        break;
                    }
                }
            }
        }

        return $exit;
    }

    private function buildReporter(string $format, ?string $outputFile): Reporter
    {
        return match ($format) {
            'json'  => new JsonReporter($outputFile),
            'html'  => new HtmlReporter($outputFile),
            'sarif' => new SarifReporter($outputFile),
            default => new ConsoleReporter(),
        };
    }
}
