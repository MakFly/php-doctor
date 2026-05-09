<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime\Symfony;

use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;

/**
 * Collects Symfony bundle list via
 * `bin/console debug:container --parameter=kernel.bundles --format=json`.
 */
final class BundleCollector
{
    public function __construct(
        private readonly ProcessExecutorInterface $exec,
        private readonly FindingBag      $bag,
    ) {}

    /**
     * @return array<mixed>|null  Decoded bundle map, or null if unavailable.
     */
    public function collect(FrameworkContext $ctx): ?array
    {
        if ($ctx->framework !== Framework::Symfony || $ctx->consoleBinary === null) {
            return null;
        }

        $result = $this->exec->run(
            ['php', $ctx->consoleBinary, 'debug:container', '--parameter=kernel.bundles', '--format=json', '--env=dev', '--no-debug'],
            $ctx->rootPath,
        );

        if (!$result->isSuccessful) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.symfony.bundles-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Symfony bundle introspection failed — kernel.bundles parameter unavailable.',
                file:     null,
                line:     null,
                fixHint:  $result->stderr ?: null,
            ));
            return null;
        }

        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded)) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.symfony.bundles-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Symfony debug:container --parameter=kernel.bundles returned non-JSON output.',
                file:     null,
                line:     null,
                fixHint:  substr($result->stdout, 0, 200) ?: null,
            ));
            return null;
        }

        return $decoded;
    }
}
