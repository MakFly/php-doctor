<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Ast;

use PhpParser\ErrorHandler;
use PhpParser\Node;

/**
 * Per-scan AST cache. Not readonly because the cache mutates on demand.
 */
final class AstSnapshot
{
    /**
     * Cache keyed by absolute file path. Value is Node[] or null (parse error).
     *
     * @var array<string, Node[]|null>
     */
    private array $cache = [];

    public function __construct(
        private readonly ParserPool  $parserPool,
        private readonly ErrorHandler $errorHandler,
    ) {}

    /**
     * Return parsed AST for the given absolute path, memoising the result.
     *
     * Returns null when the file cannot be read or parsed.
     *
     * @return Node[]|null
     */
    public function forFile(string $absolutePath): ?array
    {
        if (array_key_exists($absolutePath, $this->cache)) {
            return $this->cache[$absolutePath];
        }

        $code = @file_get_contents($absolutePath);
        if ($code === false) {
            $this->cache[$absolutePath] = null;
            return null;
        }

        $stmts = $this->parserPool->getParser()->parse($code, $this->errorHandler);
        $this->cache[$absolutePath] = $stmts; // may be null on parse failure

        return $stmts;
    }

    /**
     * Number of files that have been attempted (including failures).
     * Useful for assertions in tests.
     */
    public function parsedFileCount(): int
    {
        return count($this->cache);
    }
}
