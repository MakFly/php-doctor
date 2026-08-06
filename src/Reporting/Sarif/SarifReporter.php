<?php

declare(strict_types=1);

namespace PhpDoctor\Reporting\Sarif;

use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Reporting\Reporter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders scan results as a SARIF 2.1.0 document.
 *
 * SARIF (Static Analysis Results Interchange Format) is the format expected by
 * GitHub Code Scanning and other CI integrations. The produced document follows
 * the minimum required keys for GitHub Code Scanning ingestion.
 *
 * @see https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html
 */
final class SarifReporter implements Reporter
{
    private const SARIF_VERSION    = '2.1.0';
    private const SARIF_SCHEMA_URI = 'https://docs.oasis-open.org/sarif/sarif/v2.1.0/cos02/schemas/sarif-schema-2.1.0.json';
    private const TOOL_VERSION     = '0.1.0';
    private const TOOL_INFO_URI    = 'https://github.com/dev-toolings/php-doctor';

    public function __construct(
        private readonly ?string $outputFile = null,
    ) {}

    public function render(
        FindingBag       $bag,
        Score            $score,
        FrameworkContext $ctx,
        OutputInterface  $output,
    ): int {
        $rootPath = rtrim($ctx->rootPath, '/');

        // Build deduplicated rules list and results in a single pass.
        $rules   = [];
        $results = [];

        foreach ($bag->all() as $finding) {
            $ruleId = $finding->ruleId;

            // Deduplicate rules by ruleId.
            if (!isset($rules[$ruleId])) {
                $rules[$ruleId] = [
                    'id'                   => $ruleId,
                    'name'                 => $this->ruleIdToName($ruleId),
                    'shortDescription'     => ['text' => $ruleId],
                    'defaultConfiguration' => ['level' => $this->severityToLevel($finding->severity)],
                ];
            }

            // Build result entry.
            $result = [
                'ruleId'    => $ruleId,
                'level'     => $this->severityToLevel($finding->severity),
                'message'   => ['text' => $finding->message],
                'locations' => [],
            ];

            if ($finding->file !== null) {
                $outOfTree = !str_starts_with($finding->file, $rootPath . '/');

                if ($outOfTree) {
                    // File is outside the project root: emit only the basename to
                    // avoid leaking absolute filesystem paths (e.g. vendor or /etc).
                    // Option B: keep physicalLocation with basename + property bag flag.
                    $uri = basename($finding->file);
                    $physicalLocation = [
                        'artifactLocation' => ['uri' => $uri],
                        'properties'       => ['outOfTreePath' => true],
                    ];
                } else {
                    $uri = $this->toRelativeUri($finding->file, $rootPath);
                    $physicalLocation = [
                        'artifactLocation' => ['uri' => $uri],
                    ];
                }

                $region = [];
                if ($finding->line !== null) {
                    $region['startLine'] = $finding->line;
                }
                if ($region !== []) {
                    $physicalLocation['region'] = $region;
                }

                $result['locations'][] = [
                    'physicalLocation' => $physicalLocation,
                ];
            }

            $results[] = $result;
        }

        $document = [
            'version' => self::SARIF_VERSION,
            '$schema' => self::SARIF_SCHEMA_URI,
            'runs'    => [
                [
                    'tool'    => [
                        'driver' => [
                            'name'           => 'php-doctor',
                            'version'        => self::TOOL_VERSION,
                            'informationUri' => self::TOOL_INFO_URI,
                            'rules'          => array_values($rules),
                        ],
                    ],
                    'results' => $results,
                ],
            ],
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

    /**
     * Map a Severity enum value to a SARIF result level.
     * Critical|High → "error", Medium → "warning", Low|Info → "note".
     */
    private function severityToLevel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical, Severity::High => 'error',
            Severity::Medium                   => 'warning',
            Severity::Low, Severity::Info       => 'note',
        };
    }

    /**
     * Derive a PascalCase name from the last segment of a dotted ruleId.
     * e.g. "common.security.hardcoded-secrets" → "HardcodedSecrets"
     */
    private function ruleIdToName(string $ruleId): string
    {
        $parts     = explode('.', $ruleId);
        $lastPart  = end($parts);
        $words     = preg_split('/[-_]/', $lastPart) ?: [$lastPart];

        return implode('', array_map('ucfirst', $words));
    }

    /**
     * Convert an absolute file path to a URI relative to the project root.
     * GitHub Code Scanning requires relative URIs in artifactLocation.uri.
     *
     * e.g. "/abs/root/src/Foo.php" with root "/abs/root" → "src/Foo.php"
     */
    private function toRelativeUri(string $absolutePath, string $rootPath): string
    {
        $prefix = $rootPath . '/';
        if (str_starts_with($absolutePath, $prefix)) {
            return substr($absolutePath, strlen($prefix));
        }

        // Fallback: return the path as-is (should not happen in normal usage).
        return $absolutePath;
    }
}
