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

    /**
     * @dataProvider supportedFrameworkVersions
     */
    public function testDetectsAcrossSupportedVersions(string $package, string $constraint, Framework $expected): void
    {
        $tmp = sys_get_temp_dir() . '/phpdoctor-version-' . uniqid();
        mkdir($tmp);
        file_put_contents($tmp . '/composer.json', json_encode([
            'name'    => 'test/version-matrix',
            'require' => ['php' => '^8.3', $package => $constraint],
        ], JSON_THROW_ON_ERROR));

        try {
            $ctx = (new ProjectDetector())->detect($tmp);
            $this->assertSame($expected, $ctx->framework, "Failed for {$package}:{$constraint}");
        } finally {
            unlink($tmp . '/composer.json');
            rmdir($tmp);
        }
    }

    /** @return iterable<string,array{string,string,Framework}> */
    public static function supportedFrameworkVersions(): iterable
    {
        yield 'symfony 6'  => ['symfony/framework-bundle', '^6.4',  Framework::Symfony];
        yield 'symfony 7'  => ['symfony/framework-bundle', '^7.0',  Framework::Symfony];
        yield 'symfony 8'  => ['symfony/framework-bundle', '^8.0',  Framework::Symfony];
        yield 'laravel 10' => ['laravel/framework',        '^10.0', Framework::Laravel];
        yield 'laravel 11' => ['laravel/framework',        '^11.0', Framework::Laravel];
        yield 'laravel 12' => ['laravel/framework',        '^12.0', Framework::Laravel];
        yield 'laravel 13' => ['laravel/framework',        '^13.0', Framework::Laravel];
    }
}
