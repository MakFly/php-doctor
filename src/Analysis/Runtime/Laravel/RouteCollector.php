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
 * Collects Laravel route definitions via `php artisan route:list --json`.
 */
final class RouteCollector
{
    public function __construct(
        private readonly ProcessExecutorInterface $exec,
        private readonly FindingBag      $bag,
    ) {}

    /**
     * @return array<mixed>|null  Decoded route list, or null if unavailable.
     */
    public function collect(FrameworkContext $ctx): ?array
    {
        if ($ctx->framework !== Framework::Laravel || $ctx->consoleBinary === null) {
            return null;
        }

        $result = $this->exec->run(
            ['php', $ctx->consoleBinary, 'route:list', '--json'],
            $ctx->rootPath,
            30,
            ['APP_ENV' => 'local', 'APP_DEBUG' => '0'],
        );

        if (!$result->isSuccessful) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.laravel.routes-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Laravel route introspection failed — route:list output unavailable.',
                file:     null,
                line:     null,
                fixHint:  $result->stderr ?: null,
            ));
            return null;
        }

        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded)) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.laravel.routes-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Laravel route:list returned non-JSON output.',
                file:     null,
                line:     null,
                fixHint:  substr($result->stdout, 0, 200) ?: null,
            ));
            return null;
        }

        return $decoded;
    }
}
