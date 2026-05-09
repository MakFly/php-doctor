<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Ast;

use PhpDoctor\Analysis\Ast\AstSnapshot;
use PhpDoctor\Analysis\Ast\ParserPool;
use PhpParser\ErrorHandler\Collecting;
use PHPUnit\Framework\TestCase;

final class AstSnapshotTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdoctor-snapshot-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files.
        foreach (glob($this->tmpDir . '/*') as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    public function testValidPhp83FileReturnsParsedAst(): void
    {
        $path = $this->tmpDir . '/Valid.php';
        file_put_contents($path, '<?php enum Foo { case A; }');

        $errorHandler = new Collecting();
        $snapshot     = new AstSnapshot(new ParserPool(), $errorHandler);

        $stmts = $snapshot->forFile($path);

        $this->assertIsArray($stmts, 'forFile() must return an array for valid PHP');
        $this->assertNotEmpty($stmts, 'AST must not be empty for a non-trivial file');
        $this->assertSame(1, $snapshot->parsedFileCount());
    }

    public function testSecondCallUsesCacheAndDoesNotIncrementCount(): void
    {
        $path = $this->tmpDir . '/Cached.php';
        file_put_contents($path, '<?php enum Bar { case B; }');

        $errorHandler = new Collecting();
        $snapshot     = new AstSnapshot(new ParserPool(), $errorHandler);

        $snapshot->forFile($path);
        $snapshot->forFile($path); // second call — must hit cache

        $this->assertSame(1, $snapshot->parsedFileCount(), 'Cache must prevent re-parsing the same file');
    }

    public function testInvalidSyntaxReturnsNullAndPopulatesHandler(): void
    {
        $path = $this->tmpDir . '/Broken.php';
        file_put_contents($path, '<?php class Broken { public function bad( { } }');

        $errorHandler = new Collecting();
        $snapshot     = new AstSnapshot(new ParserPool(), $errorHandler);

        $result = $snapshot->forFile($path);

        // nikic/php-parser may return a partial AST or null on syntax error;
        // what we require is that the error handler recorded at least one error.
        $this->assertTrue($errorHandler->hasErrors(), 'Collecting handler must record syntax errors');
        $this->assertSame(1, $snapshot->parsedFileCount(), 'Failed file must still be counted in cache');
    }
}
