<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime;

use PhpDoctor\Analysis\Runtime\Composer\ComposerAuditor;
use PhpDoctor\Analysis\Runtime\Laravel\AboutCollector as LaravelAboutCollector;
use PhpDoctor\Analysis\Runtime\Laravel\ConfigCollector as LaravelConfigCollector;
use PhpDoctor\Analysis\Runtime\Laravel\MigrationCollector as LaravelMigrationCollector;
use PhpDoctor\Analysis\Runtime\Laravel\RouteCollector as LaravelRouteCollector;
use PhpDoctor\Analysis\Runtime\Symfony\BundleCollector as SymfonyBundleCollector;
use PhpDoctor\Analysis\Runtime\Symfony\EventCollector as SymfonyEventCollector;
use PhpDoctor\Analysis\Runtime\Symfony\RouteCollector as SymfonyRouteCollector;
use PhpDoctor\Analysis\Runtime\Symfony\ServiceCollector as SymfonyServiceCollector;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;

/**
 * Orchestrates all runtime collectors and aggregates results into a RuntimeSnapshot.
 *
 * Constructor dependencies are injected explicitly (no DI container) so the
 * class remains testable with plain PHPUnit without any container wiring.
 */
final class SnapshotBuilder
{
    public function __construct(
        private readonly SymfonyRouteCollector    $symfonyRoutes,
        private readonly SymfonyServiceCollector  $symfonyServices,
        private readonly SymfonyEventCollector    $symfonyEvents,
        private readonly SymfonyBundleCollector   $symfonyBundles,
        private readonly LaravelRouteCollector    $laravelRoutes,
        private readonly LaravelAboutCollector    $laravelAbout,
        private readonly LaravelConfigCollector   $laravelConfig,
        private readonly LaravelMigrationCollector $laravelMigrations,
        private readonly ComposerAuditor          $composerAuditor,
    ) {}

    /**
     * Build a RuntimeSnapshot for the given project context.
     *
     * Collectors that do not apply to the detected framework return null and
     * are stored as null in the snapshot ("not collected").
     * Errors are encoded as Findings in the FindingBag — the snapshot is
     * always returned, even when all collectors fail.
     */
    public function build(FrameworkContext $ctx, FindingBag $bag): RuntimeSnapshot
    {
        return match ($ctx->framework) {
            Framework::Symfony => new RuntimeSnapshot(
                routes:        $this->symfonyRoutes->collect($ctx),
                services:      $this->symfonyServices->collect($ctx),
                listeners:     $this->symfonyEvents->collect($ctx),
                bundles:       $this->symfonyBundles->collect($ctx),
                composerAudit: $this->composerAuditor->audit($ctx),
            ),

            Framework::Laravel => new RuntimeSnapshot(
                routes:        $this->laravelRoutes->collect($ctx),
                about:         $this->laravelAbout->collect($ctx),
                config:        $this->laravelConfig->collect($ctx),
                migrations:    $this->laravelMigrations->collect($ctx),
                composerAudit: $this->composerAuditor->audit($ctx),
            ),

            Framework::Generic => new RuntimeSnapshot(
                composerAudit: $this->composerAuditor->audit($ctx),
            ),
        };
    }
}
