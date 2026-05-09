<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Ast;

use PhpDoctor\Analysis\Ast\FileWalker;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PHPUnit\Framework\TestCase;

final class FileWalkerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/projects';

    private function makeContext(string $projectDir, array $sourcePaths): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      $projectDir,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   $sourcePaths,
        );
    }

    /**
     * symfony-min/src/ contains exactly one .php file (DummyController.php).
     * The bin/console script is NOT a .php file and must be excluded.
     */
    public function testWalkerYieldsOnlyPhpFilesForSymfonyMin(): void
    {
        $projectDir = self::FIXTURES . '/symfony-min';
        $ctx        = $this->makeContext($projectDir, [$projectDir . '/src']);

        $walker = new FileWalker();
        $files  = iterator_to_array($walker->walk($ctx));

        $this->assertCount(1, $files, 'Expected exactly 1 .php file in symfony-min/src/');

        $names = array_map(fn(\SplFileInfo $f) => $f->getFilename(), $files);
        $this->assertContains('DummyController.php', $names);
    }

    /**
     * generic/src/ contains only a .gitkeep — no PHP files.
     */
    public function testWalkerYieldsNothingForEmptySourceDir(): void
    {
        $projectDir = self::FIXTURES . '/generic';
        $ctx        = $this->makeContext($projectDir, [$projectDir . '/src']);

        $walker = new FileWalker();
        $files  = iterator_to_array($walker->walk($ctx));

        $this->assertCount(0, $files, 'Expected 0 files when source dir has no .php files');
    }

    /**
     * Empty sourcePaths must not yield anything and must not throw.
     */
    public function testWalkerWithEmptySourcePathsYieldsNothing(): void
    {
        $ctx    = $this->makeContext('/tmp', []);
        $walker = new FileWalker();
        $files  = iterator_to_array($walker->walk($ctx));

        $this->assertCount(0, $files);
    }
}
