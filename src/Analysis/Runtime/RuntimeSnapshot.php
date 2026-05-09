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
        /** Symfony debug:router / Laravel route:list output.
         * @var array<mixed>|null */
        public ?array $routes         = null,
        /** Symfony debug:container services.
         * @var array<mixed>|null */
        public ?array $services       = null,
        /** Symfony debug:event-dispatcher listeners.
         * @var array<mixed>|null */
        public ?array $listeners      = null,
        /** Symfony kernel.bundles parameter.
         * @var array<mixed>|null */
        public ?array $bundles        = null,
        /** Laravel about --json sections.
         * @var array<mixed>|null */
        public ?array $about          = null,
        /** Laravel config()->all() via php -r workaround.
         * @var array<mixed>|null */
        public ?array $config         = null,
        /** Laravel migrate:status parsed table.
         * @var array<mixed>|null */
        public ?array $migrations     = null,
        /** composer audit --format=json --locked output.
         * @var array<mixed>|null */
        public ?array $composerAudit  = null,
    ) {}
}

