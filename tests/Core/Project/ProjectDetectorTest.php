<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Core\Project;

use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\ProjectDetector;
use PHPUnit\Framework\TestCase;

final class ProjectDetectorTest extends TestCase
{
    private static string $fixtures;

    public static function setUpBeforeClass(): void
    {
        self::$fixtures = dirname(__DIR__, 2) . '/fixtures/projects';
    }

    public function testDetectsSymfony(): void
    {
        $detector = new ProjectDetector();
        $ctx = $detector->detect(self::$fixtures . '/symfony-min');

        $this->assertSame(Framework::Symfony, $ctx->framework);
        $this->assertNotNull($ctx->consoleBinary);
        $this->assertStringEndsWith('/bin/console', $ctx->consoleBinary);
        $this->assertFileExists($ctx->consoleBinary);

        $srcPaths = array_map('basename', $ctx->sourcePaths);
        $this->assertContains('src', $srcPaths);
    }

    public function testDetectsLaravel(): void
    {
        $detector = new ProjectDetector();
        $ctx = $detector->detect(self::$fixtures . '/laravel-min');

        $this->assertSame(Framework::Laravel, $ctx->framework);
        $this->assertNotNull($ctx->consoleBinary);
        $this->assertStringEndsWith('/artisan', $ctx->consoleBinary);
        $this->assertFileExists($ctx->consoleBinary);

        $srcPaths = array_map('basename', $ctx->sourcePaths);
        $this->assertContains('app', $srcPaths);
    }

    public function testDetectsGeneric(): void
    {
        $detector = new ProjectDetector();
        $ctx = $detector->detect(self::$fixtures . '/generic');

        $this->assertSame(Framework::Generic, $ctx->framework);
        $this->assertNull($ctx->consoleBinary);

        // generic/src exists so sourcePaths should contain it
        $srcPaths = array_map('basename', $ctx->sourcePaths);
        $this->assertContains('src', $srcPaths);
    }

    public function testThrowsOnMissingComposer(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/composer\.json not found/');

        $detector = new ProjectDetector();
        $detector->detect(self::$fixtures . '/no-composer');
    }

    public function testThrowsOnBrokenJson(): void
    {
        $this->expectException(\JsonException::class);

        $detector = new ProjectDetector();
        $detector->detect(self::$fixtures . '/broken');
    }

    public function testThrowsOnNonexistentPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $detector = new ProjectDetector();
        $detector->detect('/tmp/__php_doctor_nonexistent_path_' . uniqid());
    }
}
