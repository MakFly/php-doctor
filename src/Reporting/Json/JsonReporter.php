<?php

declare(strict_types=1);

namespace PhpDoctor\Reporting\Json;

use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Reporting\Reporter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders scan results as a stable JSON document (schema version "1").
 *
 * If the $outputFile option is set in the constructor, the JSON is written to
 * that file path; otherwise it is written to stdout via $output->writeln().
 */
final class JsonReporter implements Reporter
{
    private const SCHEMA_VERSION = '1';
    private const TOOL_VERSION   = '0.1.0-dev';

    public function __construct(
        private readonly ?string $outputFile = null,
    ) {}

    public function render(
        FindingBag       $bag,
        Score            $score,
        FrameworkContext $ctx,
        OutputInterface  $output,
    ): int {
        $projectName = $ctx->composerData['name'] ?? basename($ctx->rootPath);

        $findings = [];
        foreach ($bag->all() as $finding) {
            $findings[] = $finding->toArray();
        }

        $bySeverity = $bag->countBySeverity();

        $document = [
            'version' => self::SCHEMA_VERSION,
            'tool'    => [
                'name'    => 'php-doctor',
                'version' => self::TOOL_VERSION,
            ],
            'project' => [
                'framework' => $ctx->framework->value,
                'rootPath'  => $ctx->rootPath,
                'name'      => is_string($projectName) ? $projectName : basename($ctx->rootPath),
            ],
            'score' => [
                'global'     => $score->global,
                'byCategory' => $score->byCategory,
            ],
            'summary' => [
                'totalFindings' => $bag->count(),
                'bySeverity'    => [
                    'critical' => $bySeverity['critical'] ?? 0,
                    'high'     => $bySeverity['high']     ?? 0,
                    'medium'   => $bySeverity['medium']   ?? 0,
                    'low'      => $bySeverity['low']      ?? 0,
                    'info'     => $bySeverity['info']     ?? 0,
                ],
            ],
            'findings' => $findings,
        ];

        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if ($this->outputFile !== null) {
            $dir = dirname($this->outputFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->outputFile, $json);
        } else {
            $output->writeln($json);
        }

        return 0;
    }
}
