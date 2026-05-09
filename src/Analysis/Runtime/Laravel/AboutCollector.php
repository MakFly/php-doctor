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
 * Collects Laravel environment summary via `php artisan about --json`.
 */
final class AboutCollector
{
    public function __construct(
        private readonly ProcessExecutorInterface $exec,
        private readonly FindingBag      $bag,
    ) {}

    /**
     * @return array<mixed>|null  Decoded about sections, or null if unavailable.
     */
    public function collect(FrameworkContext $ctx): ?array
    {
        if ($ctx->framework !== Framework::Laravel || $ctx->consoleBinary === null) {
            return null;
        }

        $result = $this->exec->run(
            ['php', $ctx->consoleBinary, 'about', '--json'],
            $ctx->rootPath,
            30,
            ['APP_ENV' => 'local', 'APP_DEBUG' => '0'],
        );

        if (!$result->isSuccessful) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.laravel.about-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Laravel about introspection failed — about --json output unavailable.',
                file:     null,
                line:     null,
                fixHint:  $result->stderr ?: null,
            ));
            return null;
        }

        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded)) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.laravel.about-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Laravel about --json returned non-JSON output.',
                file:     null,
                line:     null,
                fixHint:  substr($result->stdout, 0, 200) ?: null,
            ));
            return null;
        }

        return $decoded;
    }
}
