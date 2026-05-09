<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Ast;

use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Reused across files per Phase 0 perf guidance.
 */
final class ParserPool
{
    /** @var Parser|null */
    private ?Parser $parser = null;

    /**
     * Returns the shared Parser instance, creating it lazily on first call.
     */
    public function getParser(): Parser
    {
        if ($this->parser === null) {
            $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        return $this->parser;
    }

    /**
     * Returns a fresh NodeTraverser on every call.
     * Visitors are ephemeral per-scan, so they must not be shared.
     */
    public function newTraverser(): NodeTraverser
    {
        return new NodeTraverser();
    }
}
