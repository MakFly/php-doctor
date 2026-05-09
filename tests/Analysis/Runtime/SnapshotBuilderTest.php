<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Runtime;

use PhpDoctor\Analysis\Runtime\Composer\ComposerAuditor;
use PhpDoctor\Analysis\Runtime\Laravel\AboutCollector as LaravelAboutCollector;
use PhpDoctor\Analysis\Runtime\Laravel\ConfigCollector as LaravelConfigCollector;
use PhpDoctor\Analysis\Runtime\Laravel\MigrationCollector as LaravelMigrationCollector;
use PhpDoctor\Analysis\Runtime\Laravel\RouteCollector as LaravelRouteCollector;
use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Analysis\Runtime\ProcessResult;
use PhpDoctor\Analysis\Runtime\RuntimeSnapshot;
use PhpDoctor\Analysis\Runtime\SnapshotBuilder;
use PhpDoctor\Analysis\Runtime\Symfony\BundleCollector as SymfonyBundleCollector;
use PhpDoctor\Analysis\Runtime\Symfony\EventCollector as SymfonyEventCollector;
use PhpDoctor\Analysis\Runtime\Symfony\RouteCollector as SymfonyRouteCollector;
use PhpDoctor\Analysis\Runtime\Symfony\ServiceCollector as SymfonyServiceCollector;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PHPUnit\Framework\TestCase;

final class SnapshotBuilderTest extends TestCase
{
    private static string $symfonyRoot;
    private static string $laravelRoot;

    public static function setUpBeforeClass(): void
    {
        self::$symfonyRoot = dirname(__DIR__, 2) . '/fixtures/projects/symfony-min';
        self::$laravelRoot = dirname(__DIR__, 2) . '/fixtures/projects/laravel-min';
    }

    /**
     * Build a SnapshotBuilder whose ProcessExecutor always returns the given JSON string.
     * For multi-call scenarios (different commands return different JSON), use $multiReturn.
     *
     * @param array<ProcessResult> $responses  Sequential responses for each exec->run() call.
     */
    private function makeBuilder(array $responses): SnapshotBuilder
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        $matcher = $exec->method('run');

        if (count($responses) === 1) {
            $matcher->willReturn($responses[0]);
        } else {
            $matcher->willReturnOnConsecutiveCalls(...$responses);
        }

        $bag = new FindingBag();

        return new SnapshotBuilder(
            symfonyRoutes:    new SymfonyRouteCollector($exec, $bag),
            symfonyServices:  new SymfonyServiceCollector($exec, $bag),
            symfonyEvents:    new SymfonyEventCollector($exec, $bag),
            symfonyBundles:   new SymfonyBundleCollector($exec, $bag),
            laravelRoutes:    new LaravelRouteCollector($exec, $bag),
            laravelAbout:     new LaravelAboutCollector($exec, $bag),
            laravelConfig:    new LaravelConfigCollector($exec, $bag),
            laravelMigrations: new LaravelMigrationCollector($exec, $bag),
            composerAuditor:  new ComposerAuditor($exec, $bag),
        );
    }

    private function makeSymfonyCtx(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Symfony,
            rootPath:      self::$symfonyRoot,
            consoleBinary: self::$symfonyRoot . '/bin/console',
            composerData:  [],
            sourcePaths:   [],
        );
    }

    private function makeGenericCtx(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      self::$symfonyRoot,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );
    }

    public function testSymfonySnapshotHasRoutesServicesListenersBundles(): void
    {
        $routesJson    = (string) file_get_contents(self::$symfonyRoot . '/fixture-router.json');
        $containerJson = (string) file_get_contents(self::$symfonyRoot . '/fixture-container.json');
        $eventsJson    = (string) file_get_contents(self::$symfonyRoot . '/fixture-events.json');
        $bundlesJson   = (string) file_get_contents(self::$symfonyRoot . '/fixture-bundles.json');
        $auditJson     = json_encode(['advisories' => [], 'abandoned' => []]);
        $composerVersion = 'Composer version 2.7.2 2024-03-11';

        $ok = static fn(string $json) => new ProcessResult(exitCode: 0, stdout: $json, stderr: '');

        $bag     = new FindingBag();
        $exec    = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')->willReturnOnConsecutiveCalls(
            $ok($routesJson),
            $ok($containerJson),
            $ok($eventsJson),
            $ok($bundlesJson),
            $ok($composerVersion),
            $ok((string) $auditJson),
        );

        $builder = new SnapshotBuilder(
            symfonyRoutes:    new SymfonyRouteCollector($exec, $bag),
            symfonyServices:  new SymfonyServiceCollector($exec, $bag),
            symfonyEvents:    new SymfonyEventCollector($exec, $bag),
            symfonyBundles:   new SymfonyBundleCollector($exec, $bag),
            laravelRoutes:    new LaravelRouteCollector($exec, $bag),
            laravelAbout:     new LaravelAboutCollector($exec, $bag),
            laravelConfig:    new LaravelConfigCollector($exec, $bag),
            laravelMigrations: new LaravelMigrationCollector($exec, $bag),
            composerAuditor:  new ComposerAuditor($exec, $bag),
        );

        $snapshot = $builder->build($this->makeSymfonyCtx(), $bag);

        $this->assertInstanceOf(RuntimeSnapshot::class, $snapshot);
        $this->assertIsArray($snapshot->routes);
        $this->assertArrayHasKey('app_index', $snapshot->routes);
        $this->assertIsArray($snapshot->services);
        $this->assertArrayHasKey('definitions', $snapshot->services);
        $this->assertIsArray($snapshot->listeners);
        $this->assertIsArray($snapshot->bundles);
        $this->assertIsArray($snapshot->composerAudit);

        // Laravel-specific fields should be null for Symfony
        $this->assertNull($snapshot->about);
        $this->assertNull($snapshot->config);
        $this->assertNull($snapshot->migrations);
    }

    public function testGenericSnapshotOnlyRunsComposerAudit(): void
    {
        $composerVersion = 'Composer version 2.7.2 2024-03-11';
        $auditJson       = json_encode(['advisories' => [], 'abandoned' => []]);

        $bag  = new FindingBag();
        $exec = $this->createMock(ProcessExecutorInterface::class);
        // Only 2 calls expected: composer --version + composer audit
        $exec->expects($this->exactly(2))
             ->method('run')
             ->willReturnOnConsecutiveCalls(
                 new ProcessResult(exitCode: 0, stdout: $composerVersion, stderr: ''),
                 new ProcessResult(exitCode: 0, stdout: (string) $auditJson, stderr: ''),
             );

        $builder = new SnapshotBuilder(
            symfonyRoutes:    new SymfonyRouteCollector($exec, $bag),
            symfonyServices:  new SymfonyServiceCollector($exec, $bag),
            symfonyEvents:    new SymfonyEventCollector($exec, $bag),
            symfonyBundles:   new SymfonyBundleCollector($exec, $bag),
            laravelRoutes:    new LaravelRouteCollector($exec, $bag),
            laravelAbout:     new LaravelAboutCollector($exec, $bag),
            laravelConfig:    new LaravelConfigCollector($exec, $bag),
            laravelMigrations: new LaravelMigrationCollector($exec, $bag),
            composerAuditor:  new ComposerAuditor($exec, $bag),
        );

        $snapshot = $builder->build($this->makeGenericCtx(), $bag);

        $this->assertNull($snapshot->routes);
        $this->assertNull($snapshot->services);
        $this->assertNull($snapshot->listeners);
        $this->assertNull($snapshot->bundles);
        $this->assertIsArray($snapshot->composerAudit);
    }
}
