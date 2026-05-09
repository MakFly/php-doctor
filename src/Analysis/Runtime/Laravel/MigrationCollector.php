<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime\Laravel;

use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;

/**
 * Collects Laravel migration status via `php artisan migrate:status`.
 *
 * NOTE: `migrate:status` has no --json flag (as of Laravel 11/12). The output
 * is a text table rendered by Symfony Console. We parse it with a tolerant
 * regex that extracts the migration name and whether it was run.
 *
 * Expected table format (example):
 *   +------+----------------------------------------------------+-------+
 *   | Ran? | Migration                                          | Batch |
 *   +------+----------------------------------------------------+-------+
 *   | Yes  | 2014_10_12_000000_create_users_table               | 1     |
 *   | No   | 2024_01_01_000000_create_jobs_table                |       |
 *   +------+----------------------------------------------------+-------+
 */
final class MigrationCollector
{
    public function __construct(
        private readonly ProcessExecutorInterface $exec,
        private readonly FindingBag      $bag,
    ) {}

    /**
     * @return list<array{name: string, ran: bool}>|null
     */
    public function collect(FrameworkContext $ctx): ?array
    {
        if ($ctx->framework !== Framework::Laravel || $ctx->consoleBinary === null) {
            return null;
        }

        $result = $this->exec->run(
            ['php', $ctx->consoleBinary, 'migrate:status'],
            $ctx->rootPath,
            30,
            ['APP_ENV' => 'local', 'APP_DEBUG' => '0'],
        );

        // migrate:status exits 0 whether or not migrations are pending.
        // Non-zero usually means DB connection failure or missing migrations table.
        if (!$result->isSuccessful) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.laravel.migrations-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Laravel migration status unavailable — migrate:status returned non-zero exit.',
                file:     null,
                line:     null,
                fixHint:  $result->stderr ?: null,
            ));
            return null;
        }

        $migrations = $this->parseTable($result->stdout);

        if ($migrations === null) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.laravel.migrations-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Migration status not parseable — migrate:status output format not recognised.',
                file:     null,
                line:     null,
                fixHint:  substr($result->stdout, 0, 300) ?: null,
            ));
            return null;
        }

        return $migrations;
    }

    /**
     * Parse the text table output of `php artisan migrate:status`.
     *
     * Tolerant parser: works on both the classic box-drawing table (Laravel 10)
     * and the Symfony Console table (Laravel 11+). We look for lines that
     * contain a recognisable "Ran?" cell (Yes / No / Pending) followed by a
     * migration name token.
     *
     * @return list<array{name: string, ran: bool}>|null  null if no rows matched.
     */
    private function parseTable(string $output): ?array
    {
        $rows = [];

        foreach (explode("\n", $output) as $line) {
            // Match table rows in formats like:
            //   | Yes  | 2014_10_12_000000_create_users_table   | 1 |
            //   | No   | 2024_01_01_000000_create_jobs_table    |   |
            //   | Pending | 2024_01_01_000000_create_xxx_table  |   |
            //
            // Also matches lines without leading pipes (plain text fallback):
            //   Yes   2014_10_12_000000_create_users_table
            if (preg_match(
                '/[\|│]?\s*(Yes|No|Pending)\s*[\|│]\s*([0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_\S+)/i',
                $line,
                $m,
            )) {
                $rows[] = [
                    'name' => trim($m[2]),
                    'ran'  => strtolower($m[1]) === 'yes',
                ];
            }
        }

        return $rows !== [] ? $rows : null;
    }
}
