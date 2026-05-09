<?php

declare(strict_types=1);

namespace PhpDoctor\Reporting\Html;

use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Reporting\Reporter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders scan results as a self-contained HTML report using Twig.
 *
 * ## Template resolution
 *
 * When running from source, the templates/ directory sits at the project root,
 * three levels above this file (src/Reporting/Html/ → src/Reporting/ → src/ → root).
 *
 * When running from a PHAR, __DIR__ resolves inside the PHAR stream wrapper
 * (phar://<path>/src/Reporting/Html). In that case, the relative path does not
 * exist on the filesystem. We detect this and fall back to the PHAR-internal stream.
 *
 * ## Output
 *
 * By default, the report is written to <rootPath>/build/php-doctor-report.html.
 * Pass an explicit $outputFile to the constructor to override.
 */
final class HtmlReporter implements Reporter
{
    private const TOOL_VERSION  = '0.1.0-dev';
    private const DEFAULT_BUILD = 'build/php-doctor-report.html';

    public function __construct(
        private readonly ?string $outputFile = null,
    ) {}

    public function render(
        FindingBag       $bag,
        Score            $score,
        FrameworkContext $ctx,
        OutputInterface  $output,
    ): int {
        $input = new ArrayInput([]);
        $io    = new SymfonyStyle($input, $output);

        $twig = $this->buildTwig();

        // Prepare template data
        $projectName = $ctx->composerData['name'] ?? basename($ctx->rootPath);
        $projectName = is_string($projectName) ? $projectName : basename($ctx->rootPath);

        $bySeverity = $bag->countBySeverity();

        $findingsByCategory = [];
        foreach ($bag->byCategory() as $cat => $catFindings) {
            $findingsByCategory[$cat] = array_map(
                static fn ($f) => $f->toArray(),
                $catFindings,
            );
        }

        $allFindings = [];
        foreach ($bag->all() as $f) {
            $allFindings[] = $f->toArray();
        }

        $jsonData = json_encode(
            [
                'version'  => '1',
                'tool'     => ['name' => 'php-doctor', 'version' => self::TOOL_VERSION],
                'project'  => ['framework' => $ctx->framework->value, 'rootPath' => $ctx->rootPath, 'name' => $projectName],
                'score'    => $score->toArray(),
                'summary'  => [
                    'totalFindings' => $bag->count(),
                    'bySeverity'    => [
                        'critical' => $bySeverity['critical'] ?? 0,
                        'high'     => $bySeverity['high']     ?? 0,
                        'medium'   => $bySeverity['medium']   ?? 0,
                        'low'      => $bySeverity['low']      ?? 0,
                        'info'     => $bySeverity['info']     ?? 0,
                    ],
                ],
                'findings' => $allFindings,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $html = $twig->render('report.html.twig', [
            'project'            => [
                'name'      => $projectName,
                'framework' => $ctx->framework->value,
                'rootPath'  => $ctx->rootPath,
            ],
            'score'              => [
                'global'     => $score->global,
                'byCategory' => $score->byCategory,
            ],
            'summary'            => [
                'totalFindings' => $bag->count(),
                'bySeverity'    => [
                    'critical' => $bySeverity['critical'] ?? 0,
                    'high'     => $bySeverity['high']     ?? 0,
                    'medium'   => $bySeverity['medium']   ?? 0,
                    'low'      => $bySeverity['low']      ?? 0,
                    'info'     => $bySeverity['info']     ?? 0,
                ],
            ],
            'findingsByCategory' => $findingsByCategory,
            'generatedAt'        => date('Y-m-d H:i:s T'),
            'jsonData'           => $jsonData,
        ]);

        $target = $this->resolveOutputFile($ctx);
        $dir    = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($target, $html);

        $io->success("HTML report written to: {$target}");

        return 0;
    }

    /**
     * Build a Twig Environment that resolves templates/ whether running from source
     * or from inside a PHAR archive.
     *
     * - Source run: __DIR__ is .../src/Reporting/Html/
     *   → templates/ is three levels up.
     * - PHAR run: __DIR__ is phar://path.phar/src/Reporting/Html/
     *   → relative path still works because FilesystemLoader uses stream wrappers.
     *   Fallback to explicit phar:// path for robustness.
     */
    private function buildTwig(): Environment
    {
        $sourceRelative = __DIR__ . '/../../../templates';

        if (is_dir($sourceRelative)) {
            $templateDir = $sourceRelative;
        } else {
            // Running from PHAR: build the stream-wrapper path explicitly.
            $pharPath    = \Phar::running(false);
            $templateDir = $pharPath !== ''
                ? 'phar://' . $pharPath . '/templates'
                : $sourceRelative; // last-resort: let Twig raise its own error
        }

        return new Environment(
            new FilesystemLoader($templateDir),
            ['autoescape' => 'html'],
        );
    }

    private function resolveOutputFile(FrameworkContext $ctx): string
    {
        if ($this->outputFile !== null) {
            return $this->outputFile;
        }
        return $ctx->rootPath . '/' . self::DEFAULT_BUILD;
    }
}
