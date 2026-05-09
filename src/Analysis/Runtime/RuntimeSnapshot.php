<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime;

/**
 * Immutable snapshot of runtime introspection data collected from
 * framework CLI commands (bin/console, php artisan).
 *
 * Every property is nullable — null means "not collected" (either because
 * the collector was not applicable for the detected framework, or because
 * the command failed). Use an empty array for "collected but empty".
 */
final readonly class RuntimeSnapshot
{
    public function __construct(
        /** Symfony debug:router / Laravel route:list output. */
        public ?array $routes         = null,
        /** Symfony debug:container services. */
        public ?array $services       = null,
        /** Symfony debug:event-dispatcher listeners. */
        public ?array $listeners      = null,
        /** Symfony kernel.bundles parameter. */
        public ?array $bundles        = null,
        /** Laravel about --json sections. */
        public ?array $about          = null,
        /** Laravel config()->all() via php -r workaround. */
        public ?array $config         = null,
        /** Laravel migrate:status parsed table. */
        public ?array $migrations     = null,
        /** composer audit --format=json --locked output. */
        public ?array $composerAudit  = null,
    ) {}
}

