<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Ast;

use PhpDoctor\Analysis\Ast\ParserPool;
use PHPUnit\Framework\TestCase;

final class ParserPoolTest extends TestCase
{
    public function testReusesSameParserInstance(): void
    {
        $pool   = new ParserPool();
        $first  = $pool->getParser();
        $second = $pool->getParser();

        $this->assertSame($first, $second, 'ParserPool must return the same Parser instance on repeated calls');
    }

    public function testNewTraverserReturnsFresh(): void
    {
        $pool = new ParserPool();

        $this->assertNotSame(
            $pool->newTraverser(),
            $pool->newTraverser(),
            'Each newTraverser() call must return a distinct NodeTraverser instance',
        );
    }
}
