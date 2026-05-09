<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Ast;

use PhpDoctor\Core\Project\FrameworkContext;
use Symfony\Component\Finder\Finder;

/**
 * Yields PHP source files for each path declared in FrameworkContext::$sourcePaths.
 */
final class FileWalker
{
    /**
     * Walk all source paths defined in the context and yield PHP files.
     *
     * Excluded directories: vendor/, node_modules/, var/cache/,
     * storage/framework/, bootstrap/cache/.
     *
     * @return \Generator<\SplFileInfo>
     */
    public function walk(FrameworkContext $ctx): \Generator
    {
        foreach ($ctx->sourcePaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $finder = Finder::create()
                ->files()
                ->in($path)
                ->name('*.php')
                ->notPath('vendor')
                ->notPath('node_modules')
                ->notPath('var/cache')
                ->notPath('storage/framework')
                ->notPath('bootstrap/cache')
                ->ignoreVCS(true);

            foreach ($finder as $file) {
                yield $file;
            }
        }
    }
}
