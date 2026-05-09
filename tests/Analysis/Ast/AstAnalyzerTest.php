<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Ast;

use PhpDoctor\Analysis\Ast\AstAnalyzer;
use PhpDoctor\Analysis\Ast\FileWalker;
use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PHPUnit\Framework\TestCase;

final class AstAnalyzerTest extends TestCase
{
    /**
     * Fixture contains:
     *   - ValidFile.php   → valid PHP 8.3 (readonly class + enum)
     *   - BrokenFile.php  → intentional syntax error
     */
    private const FIXTURE_DIR = __DIR__ . '/../../fixtures/projects/with-php';

    private function makeContext(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      self::FIXTURE_DIR,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [self::FIXTURE_DIR],
        );
    }

    public function testBothFilesAttemptedAndBrokenOneEmitsFinding(): void
    {
        $pool     = new ParserPool();
        $walker   = new FileWalker();
        $analyzer = new AstAnalyzer($pool, $walker);
        $bag      = new FindingBag();
        $ctx      = $this->makeContext();

        $snapshot = $analyzer->analyze($ctx, $bag, []);

        // Both files must have been attempted (parsedFileCount counts cache entries).
        $this->assertSame(2, $snapshot->parsedFileCount(), 'Both files must be attempted by the analyzer');

        // Exactly one parse-error finding for the broken file.
        $this->assertSame(1, $bag->count(), 'The broken file must produce exactly 1 parse-error finding');

        $findings = iterator_to_array($bag->all());
        $this->assertSame('php-doctor.parse-error', $findings[0]->ruleId);
    }
}
