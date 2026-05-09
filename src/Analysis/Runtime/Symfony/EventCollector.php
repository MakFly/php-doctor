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
 * Collects Symfony event listeners via `bin/console debug:event-dispatcher --format=json`.
 */
final class EventCollector
{
    public function __construct(
        private readonly ProcessExecutorInterface $exec,
        private readonly FindingBag      $bag,
    ) {}

    /**
     * @return array<mixed>|null  Decoded event-listener map, or null if unavailable.
     */
    public function collect(FrameworkContext $ctx): ?array
    {
        if ($ctx->framework !== Framework::Symfony || $ctx->consoleBinary === null) {
            return null;
        }

        $result = $this->exec->run(
            ['php', $ctx->consoleBinary, 'debug:event-dispatcher', '--format=json', '--env=dev', '--no-debug'],
            $ctx->rootPath,
        );

        if (!$result->isSuccessful) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.symfony.listeners-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Symfony event-dispatcher introspection failed — debug:event-dispatcher output unavailable.',
                file:     null,
                line:     null,
                fixHint:  $result->stderr ?: null,
            ));
            return null;
        }

        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded)) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.symfony.listeners-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Symfony debug:event-dispatcher returned non-JSON output.',
                file:     null,
                line:     null,
                fixHint:  substr($result->stdout, 0, 200) ?: null,
            ));
            return null;
        }

        return $decoded;
    }
}
