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
 * Collects Symfony DI container services via `bin/console debug:container --format=json`.
 */
final class ServiceCollector
{
    public function __construct(
        private readonly ProcessExecutorInterface $exec,
        private readonly FindingBag      $bag,
    ) {}

    /**
     * @return array<mixed>|null  Decoded container data, or null if unavailable.
     */
    public function collect(FrameworkContext $ctx): ?array
    {
        if ($ctx->framework !== Framework::Symfony || $ctx->consoleBinary === null) {
            return null;
        }

        $result = $this->exec->run(
            ['php', $ctx->consoleBinary, 'debug:container', '--format=json', '--env=dev', '--no-debug'],
            $ctx->rootPath,
        );

        if (!$result->isSuccessful) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.symfony.services-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Symfony service introspection failed — debug:container output unavailable.',
                file:     null,
                line:     null,
                fixHint:  $result->stderr ?: null,
            ));
            return null;
        }

        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded)) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.symfony.services-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Symfony debug:container returned non-JSON output.',
                file:     null,
                line:     null,
                fixHint:  substr($result->stdout, 0, 200) ?: null,
            ));
            return null;
        }

        return $decoded;
    }
}
